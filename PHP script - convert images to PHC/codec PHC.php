<?php

/* Here, the actual compression codec for PHC images. Input is a text string containing 0s and 1s either in blocks of
16, representing individual 16-bit RGB565 pixel values, or in blocks of 8 or less representing the pixel index in the
associated palette; values are separated by spaces. A custom form of Run Length Encoding is used.

The PHC compression format:

The actual pixel data starts with a control bit. For a control bit of one, the next few bits indicate the length of the
pixel offset, i.e. the number of pixels to be read directly before the next control bit is encountered. The number of
bits used to encode this offset is set by variable _bits_offset. For example, if the _bits_offset is 3, we read 3 bits
following the control bit, i.e.:
      
    1 (control bit) 101 (3-bit offset)
      
Which indicates an offset of 5. Since we're always reading at least one pixel, we increment the offset of 5 to get the
actual offset of 6. Thus, we read six pixels' worth of data, and then read our next control bit.
      
For a control bit of zero, the following few bits indicate the number of times the previous pixel is to be repeated. 
The number of bits used to encode this value is specified by _bits_repeats. For a _bits_repeats of 2:
      
    0 (control bit) 01 (2-bit repeats)

the repeat value as read is 1. But, a repeat must occur at least once, we increment _repeats to get the actual repeat
value used, or 2. We'll write the last pixel used to our pixel buffer two more times, and then to screen.

Regular expression matching is used to determine the actual optimal offset and repeat values for compression. If 
compression does not result in size savings, it is not used. 

Note that compression does not slow down file handling on the target processor, but typically speeds it up: writing
repeats without processing is much faster than reading and processing individual pixels. */


if ($compression_report_verbose) {                          //if verbose compression results are desired, report these
     $ctime = -microtime(1);                                //log start time of code execution;
     echo '<p>Bytes used for pixel data: '.number_format($pixel_byte_count);
     echo '<br>Compressing on '.BIT_DEPTH.'-bit blocks (1 pixel each):<p><pre>';
}



/* Determine optimum bit size to encode repeats by counting length of each section of repeated pixels, then calculating
the number of code blocks that would be required to store the length of this section at a given bit depth. */

define('PIXEL_BLOCK_LENGTH', BIT_DEPTH + 1);                //length of a pixel block in string $pixel_bit_stream
                                                            //(including terminal space)
$total_pixels_removed = array(0, 0, 0, 0, 0, 0, 0, 0);      //save number of pixels encoded using repeat blocks of
                                                            //various bit sizes 
$total_blocks_added = array(0, 0, 0, 0, 0, 0, 0, 0);        //and the number of blocks of pixel repeat codes added

$re = '/([01]{'.BIT_DEPTH.'} )\1+/';                        //regular expression: each pixel with its BIT_DEPTH worth 
                                                            //of 0 and 1s followed by a space
preg_match_all($re, $pixel_bit_stream, $pm);                //find all matches in the stream
foreach ($pm[0] as $v) {                                    //for each section of repeated pixels
     $repeats = strlen($v)/PIXEL_BLOCK_LENGTH - 1;          //get its length less 1 as the first pixel is no repeat
     for ($i = 0; $i < 8; $i++) {                           //determine how many repeat code blocks would be needed for
                                                            //repeat blocks 0 to 7 bits in size
          $repeat_block_count = $repeats >> $i;             //calculate number of blocks encoding the max number of 
                                                            //repeats
          $pixels_removed = $repeat_block_count << $i;      //remove this number of pixels from the total
          $total_pixels_removed[$i] += $pixels_removed;     //and store it to an array that sums pixels removed at each
                                                            //bit depth, to determine which bit depth is most efficient
          if ($remaining_repeat_count = $repeats - $pixels_removed) {   //process any remaining repeated pixels
               if ($remaining_repeat_count == 1) {                      //if only one:
                    $code_length = $i + 1;                              //bit length of repeat code, plus preceding 
                                                                        //control code
                    if ($code_length <= BIT_DEPTH) {        //if less than or equal to the length of a pixel at its bit
                                                            //depth, store the last repeat as a repeat (decodes more
                                                            //efficiently)
                         $total_pixels_removed[$i]++;       //update the number of pixels removed at this bit depth
                         $repeat_block_count++;             //and the number of blocks needed to encode these repeats
                    }
               }
               else {                                       //if more than one repeat remains, encode as another repeat
                                                            //block
                    $total_pixels_removed[$i] += $remaining_repeat_count;   //log the additional pixels removed
                    $repeat_block_count++;                  //and the code block required
               }
          }
          $total_blocks_added[$i] += $repeat_block_count;   //store the number of repeat codes added at given bit
                                                            //depth for the repeat code block
     }
}
unset($pm);                                                 //remove matches from memory



/* Do the math: how many bits are removed per pixel, and how many are added at each bit depth for the repeat codes. 
The actual number of repeats is n+1, because the presence of a repeat code itself indicates at least one repeat. So we
start with zero bits to encode repeats - the control code followed by no repeat code bits still indicates a single
repeat, which may be the most efficient especially for some true-colour images. */

$opt_bits_repeats = 0;                                      //start with an optimum repeat bit depth of 0
$prev_bits_saved = 0;                                       //initialize $prev_bits_saved to 0              
for ($i = 0; $i < 8; $i++) {                                //for each bit depth used for repeat codes,
     $bits_removed = $total_pixels_removed[$i] * BIT_DEPTH; //bits removed = pixels * pixel bit depth
     $code_length = $i + 1;                                 //size of each repeat section at each bit depth: add one
                                                            //bit for the control code
     $bits_added = $total_blocks_added[$i] * $code_length;  //bits added = repeat code blocks * repeat code length
     $bits_saved = $bits_removed - $bits_added;             //total number of bits saved at this bit depth
     if ($compression_report_verbose) echo $i.'-bit repeat code: '.$bits_saved.' bits or '.($bits_saved >> 3).' bytes saved<br>';
     if ($bits_saved > $prev_bits_saved) {                  //if the number of bits saved is greater than previous
          $opt_bits_repeats = $i;                           //save this as the new optimum bit depth for repeat codes
          $prev_bits_saved = $bits_saved;                   //and store bits saved for the next code loop
     }
}
define('BITS_REPEATS', $opt_bits_repeats);                  //save the final optimum bit depth as a constant
if ($compression_report_verbose) echo "Optimal bit size for storing pixel repeats: $opt_bits_repeats<p>";



/* Replace repeating pixels with control code of 0 followed by the number of repeats at the given repeat bit depth, as
many times as required. Append '* ' to each code block to differentiate repeat codes from unrepeated pixels in the bit
stream, to allow further regular expression processing.

Start with determining the maximum value for this repeat code at the desired bit depth, i.e. a control code of 0
followed by a 1 in each following bit position. For example, for an optimal repeats bit depth of 3, the maximum-repeat
code is 0111*, representing 7+1 or 8 repeats per max-repeat code block. 

Single repeats are stored as a repeat block. This usually uses the same number of bits, or fewer, than the actual pixel
code. This saves on space, but more importantly, repeats require no looking up; the previous bit is simply copied to 
the output buffer again. Additionally, we may save ourselves an additional pixel offset code by doing so. Example at
repeat bit depth of 3: 0000* (remember, at n+1, this amounts to 1 repeat). */

$max_repeat_code = '0';                                         //start max value code string
for ($i = 0; $i < BITS_REPEATS; $i++) $max_repeat_code .= '1';  //add 1 for each bit position
define('MAX_REPEAT_CODE', $max_repeat_code.'* ');               //and append a '* '; save as a constant

$comp0 = preg_replace_callback($re, function ($pr) {        //create a new compressed bit stream from 
                                                            //$pixel_bit_stream, using previous regex
     $s = $pr[1];                                           //matched subpattern: bit code of the repeated pixel starts 
                                                            //our encode string
     $repeats = strlen($pr[0])/PIXEL_BLOCK_LENGTH;          //get the length of the repeated pixel code and divide by
                                                            //number of characters used for each pixel
     $repeats--;                                            //less 1 as the first pixel is no repeat
     $repeat_block_count = $repeats >> BITS_REPEATS;        //number of blocks encoding the max number of repeats
     if ($repeat_block_count)                               //for each of which
        for ($i = 0; $i < $repeat_block_count; $i++) $s .= MAX_REPEAT_CODE;     //add a max-repeat code to the encode
                                                                                //string 
     $repeats_removed = $repeat_block_count << BITS_REPEATS;                    //calculate number of repeats removed
     if ($remaining_repeat_count = $repeats - $repeats_removed)                 //if any repeats remaining
          $s .= sprintf('0%0'.BITS_REPEATS.'b* ', $remaining_repeat_count - 1); //add to encode string, less one 
     return $s;                                             //replace matched pattern with the new code string
}, $pixel_bit_stream);



/* For remaining, non-repeating pixels, calculate length of runs between repeats, then the optimum bit size of blocks 
to encode these sections, or so-called read-forward offsets. Each block stores the number of pixels that are to be
read unaltered - and since this is at least one, the actual number to be read is n+1. The control code for read-
forward offsets is 1, so for a read forward offset bit depth of 2, the code might be 101, indicating that 1+1=2 pixels
are to be read from the file and copied to the buffer directly. */

$total_blocks_added = array(0, 0, 0, 0, 0, 0, 0, 0);        //store number of unrepeated pixel code blocks encoded at
                                                            //each code block bit depth, from 0 to 7 bits

$re = '/(?:[01]{'.BIT_DEPTH.'} )+/';                        //regex: pixel bits plus separating space; the '*' used for
                                                            //repeat blocks ensures these are not captured
preg_match_all($re, $comp0, $pm);  
foreach ($pm[0] as $v) {                                        //for each run of unrepeated pixels
     $unrepeated_pixel_count = strlen($v)/PIXEL_BLOCK_LENGTH;   //calculate number of unrepeated pixels
     for ($i = 0; $i < 8; $i++) {                               //for each bit depth for the look-forward offset 
          $max_pixels_per_block = 1 << $i;                      //calculate the maximum number of pixels that can be
                                                                //enumerated by this block
          $unrepeated_block_count = ($unrepeated_pixel_count + $max_pixels_per_block - 1) >> $i;  //faster rounding up
          $total_blocks_added[$i] += $unrepeated_block_count;   //save number of code blocks added at each bit depth
     }
}
unset($pm);

$opt_bits_offset = 0;                                       //starting look-forward offset of 0: a single pixel
for ($i = 0; $i < 8; $i++) {                                //for each bit depth for offset lengths, n+1 pixels are to
                                                            //be copied unaltered from the code string
     $code_length = $i + 1;                                 //length is code bit depth plus one for the control code
     $bits_added = $total_blocks_added[$i] * $code_length;  //bits used to store offsets at each bit depth
     if ($compression_report_verbose) echo $i.'-bit offset code: '.$bits_added.' bits or '.($bits_added >> 3).' bytes added<br>';

     if (!@$prev_bits_added) $prev_bits_added = $bits_added;  //if not already defined, store the number of bits added
     if ($bits_added < $prev_bits_added) {                    //at this bit depth, are fewer bits added back in?
          $opt_bits_offset = $i;                            //if so, save new bit depth for unrepeated pixel runs
          $prev_bits_added = $bits_added;                   //store this number for the next code loop
     }
}

define('BITS_OFFSET', $opt_bits_offset);                    //save optimum bit depth for unrepeated pixel runs
$bytes_saved = ($prev_bits_saved - $prev_bits_added) >> 3;  //number of bytes saved using optimal compression
$calc_byte_count = $pixel_byte_count - $bytes_saved;        //determine byte count for new pixel bit stream
if ($compression_report_verbose) echo 'Optimal bit size for storing read-forward offset: '.BITS_OFFSET."<br>
Total bytes saved: $bytes_saved; calculated image byte count: $calc_byte_count";



/* Replace unrepeated pixels with a control code '1' followed by the offset code, where the value of the offset code
plus one indicates the number of pixels that are to read from the pixel bit stream unaltered. */

$max_offset_code = '1';                                         //control bit for offset code block
for ($i = 0; $i < BITS_OFFSET; $i++) $max_offset_code .= '1';   //max value for block of this size, as a string
define('MAX_OFFSET_CODE', $max_offset_code);                    //store as constant
define('MAX_OFFSET_LENGTH', PIXEL_BLOCK_LENGTH * (1 << BITS_OFFSET));   //length of an unrepeated pixel run of maximum
                                                                        //length

$comp0 = preg_replace_callback($re, function ($pr) {
     $s = '';                                                           //initialize replacement string
     $unrepeated_pixel_count = strlen($pr[0])/PIXEL_BLOCK_LENGTH;       //length of non-repeating pixel run
     $offset_block_count = $unrepeated_pixel_count >> BITS_OFFSET;      //number of times max offset value is stored 
     if ($offset_block_count) for ($i = 0; $i < $offset_block_count; $i++) {    //for each such block,
          $s .= MAX_OFFSET_CODE.substr($pr[0], 0, MAX_OFFSET_LENGTH);   //add the maximum offset code then the pixel
                                                                        //bits that are to be copied
          $pr[0] = substr($pr[0], MAX_OFFSET_LENGTH);                   //and remove them from the source pixel bits
     }
     $pixels_removed = $offset_block_count << BITS_OFFSET;              //count the pixels removed
     if ($remaining_offset_count = $unrepeated_pixel_count - $pixels_removed)   //for any remaining unrepeated pixels,
          $s .= sprintf('1%0'.BITS_OFFSET.'b', ($remaining_offset_count - 1)).$pr[0];   //append control code '1'
                                                                                        //then the number of remaining
                                                                                        //pixels less one
     return $s;
}, $comp0);


     
/* Final compressed bit stream - remove spaces and control characters used during compression, then pad to the nearest
two bytes, and report the bit depths used to encode pixel repeats and unrepeated pixel runs. 

Check - if the compressed code is longer than the source code, delete it and stick to source. But even if the
compressed bit stream is equal in size to the source, we'll use it. The compressed bit stream decodes faster by not 
reading individual pixels and writing them to the buffer, but simply repeating them. */

$repl = array(' ', '*');                                    //characters to remove from bit stream
$comp0 = str_replace($repl, '', $comp0);                    //do it
$comp_length = strlen($comp0);                              //number of bits in bit stream
$i = $comp_length & 15;                                     //see how many of last 16 bits have been defined
if ($i) {                                                   //if there's a remainder 
     $i = 16 - $i;                                          //calculate bits needed to pad out to a full 2 bytes
     $comp0 .= sprintf('%0'.$i.'b', '');                    //pad out the stream
     $comp_length += $i;
}
$PHC_control_code = BITS_REPEATS.', '.BITS_OFFSET;          //bits used for pixel repeats and unrepeated pixel runs

$comp_pixel_byte_count = $comp_length >> 3;                 //convert bit count to byte count

if ($comp_pixel_byte_count > $pixel_byte_count) {           //if the 'compressed' bit stream is larger than the source
     unset($comp0);                                         //delete it
     unset($PHC_control_code);                              //and delete its control codes
     echo '<p>Compressed code is longer than original. Using original image data.';
}

else {                                                      //compressed stream is smaller or equal in size to source
     if ($compression_report_verbose) {                     //report compression info if asked to
          echo "<p>PHC control code: $PHC_control_code</pre>
<p>Actual bytes in compressed pixel data: $comp_pixel_byte_count<br>
Size reduction from uncompressed source pixel data ".round(($pixel_byte_count - $comp_pixel_byte_count) / $pixel_byte_count * 100, 1).'%';
          
          $ctime += microtime(1);
          $ctime = number_format($ctime, 5, '.', '');
          echo "<p>Compression codec execution time: $ctime seconds";
     }
     $pixel_byte_count = $comp_pixel_byte_count;            //store the new byte count for pixel data
}