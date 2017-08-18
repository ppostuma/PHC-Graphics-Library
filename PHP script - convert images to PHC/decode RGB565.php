<?php

/* This code both tests our compressed PHC images, but also demonstrate2 the decoding used on the ESP8266 processor. We
closely mimic our decompression algorithm, which uses Bodmer's pushColors function to great effect.

For most of you this is obvious, but please note that this code will not work as is on the Arduino. It is PHP code,
after all. Still, I modelled it in PHP first in order to test my compression/decompression algorithm, and find it's
easier to annotate here. The PHP code should also be easier to understand for beginning programmers, which I've tried
to keep reasonably easy to read here. It's platform-independent, though does require that PHP and a suitable server are
installed.

Finally, this code displays compressed images. I would discourage disabling compression - compressed images typically
display much more quickly, due to the speed with which repeat pixels are written. That said, some (very small) images
will pack more tightly uncompressed and will be saved as such.

In this script, the two-byte variable $PHC_control_code determines the number of bits assigned to pixel repeats and the
number of pixels to be read forward. On the target microprocessor, these are stored as single bytes in PROGMEM, or 
combined into a single byte if encoded as a PHC file to be read from SPIFFS. 

If this isn't enough, the decompression process is also annotated in the PHC_graphics sketch. */

unset($pixel_bytes);                                        //remove - unused if we're decoding our compressed code
$pixel_data_bytes = str_split($pixel_bit_stream, 8);        //convert bit stream to 'bytes' or strings of 8 bits
foreach ($pixel_data_bytes as &$v) $v = bindec($v);         //convert each 'byte' to actual byte values
unset($v); 
$data_count = count($pixel_data_bytes);                     //count number of bytes used for pixel data



/* Initialize a few values; on the target microprocessor, we'd initialize pointers to $pixel_data_bytes, $palette_bytes
if defined, etc. */

$ptr = 0;                                                   //current position in data stream
$bit_index = 7;                                             //mask for reading image data bit by bit, starting at 7
$pixel_buffer = array();                                    //initialize the pixel buffer
$pixel_buffer_index = 0;                                    //and its position to the first byte, 0

$im = imagecreatetruecolor(WIDTH, HEIGHT);                  //create image resource for PHP script
$x = $y = 0;                                                //initialize x, y to first pixel position
$pixels_processed = 0;



/* If a PHC control code has been defined, the image has been compressed. Decompress it */

if (@$PHC_control_code) { 
     $a = explode(', ', $PHC_control_code);
     $bits_repeats = $a[0];                                 //number of bits used to encode repeats
     $bits_offset = $a[1];                                  //number of bits used for the read-forward offset

     readPHC_bit();                                         //read first bit of data, a control code that identifies
                                                            //whether a repeat or read-forward operation follows 

     if (BIT_DEPTH != 16)                                   //process paletted images
        while ($pixels_processed < PIXEL_COUNT) {
          while ($code_value == 1) {                        //$code_value 1: read pixels from code unaltered
               $offset = readPHC_nbits($bits_offset);       //read n number of bits to determine the offset
               ++$offset;                                   //and increment: read this many pixels' worth of data
               $pixels_processed += $offset;                //update counter for number of pixels_processed

               for ($i = 0; $i < $offset; $i++) {           //read number of pixels specified 
                    $pixel_code = readPHC_nbits(BIT_DEPTH); //read the pixel code
                    $pixel_bytes = $pixel_buffer[$pixel_buffer_index++] = $rgb[$pixel_code];  
                                                            //similar to the decode function in Arduino code, but get
                                                            //both pixel bytes from the palette, write to buffer and
                                                            //update buffer index

                    if ($pixel_buffer_index == PIXEL_BUFFER_SIZE) {     //if we're now over the screen buffer size
                         pushColors($pixel_buffer, PIXEL_BUFFER_SIZE);  //write buffer to screen
                         $pixel_buffer_index = 0;                       //and reset buffer index to 0
                    }
               }

               readPHC_bit();                               //read next control code
          } 
          
          $repeats = 0;                                                               //process pixel repeats
          while ($code_value == 0 && ($pixels_processed + $repeats) < PIXEL_COUNT) {  //$code_value 0: repeat previous
                                                                                      //pixel as long as pixels remain
               if ($bits_repeats) $repeats += readPHC_nbits($bits_repeats);   //add the number of repeats encoded
               ++$repeats;                                                    //then add one for each repeat block
               readPHC_bit();                                                 //read next control bit
          }
          if ($repeats) {
               $pixels_processed += $repeats;                                 //update number of pixels processed
               repeatPixel($repeats);
          }
     }


     else while ($pixels_processed < PIXEL_COUNT) {         //process 16-bit, unpaletted images
          while ($code_value == 1) {                        //as above: $code_value 1, read pixels from code unaltered
               $offset = readPHC_nbits($bits_offset);       //read the offset
               ++$offset;                                   //increment by one: read this many pixels' worth of data
               $pixels_processed += $offset;                //update counter

               for ($i = 0; $i < $offset; $i++) {           //read number of pixels specified 
                    $pixel_bytes = $pixel_buffer[$pixel_buffer_index++] = readPHC_nbits(16);  
                                                            //read both pixel bytes directly from data stream; add to
                                                            //buffer
                                                            
                    if ($pixel_buffer_index == PIXEL_BUFFER_SIZE) {     //if the buffer's full
                         pushColors($pixel_buffer, PIXEL_BUFFER_SIZE);  //write pixels to screen
                         $pixel_buffer_index = 0;                       //and reset buffer index to 0
                    }
               }

               readPHC_bit();                               //read next control code
          } 

          $repeats = 0;                                     //repeats: handle exactly as above
          while ($code_value == 0 && ($pixels_processed + $repeats) < PIXEL_COUNT) {
               if ($bits_repeats) $repeats += readPHC_nbits($bits_repeats);
               ++$repeats;
               readPHC_bit();
          }
          if ($repeats) {
               $pixels_processed += $repeats;
               repeatPixel($repeats);
          }
     }
}


/* If no compression parameters are defined, simply read each pixel and when the buffer is full, write pixels to screen
using Bodmer's pushColors() function. */

else {
     if (BIT_DEPTH != 16) {                                               //paletted images:
          for ($i = 0; $i < PIXEL_COUNT; $i++) {                          //for each pixel,
               $pixel_code = readPHC_nbits(BIT_DEPTH);                    //read the number of bits used per pixel
               $pixel_buffer[$pixel_buffer_index++] = $rgb[$pixel_code];  //read both pixel bytes from palette

               if ($pixel_buffer_index == PIXEL_BUFFER_SIZE) {            //and if the pixel buffer is full
                    pushColors($pixel_buffer, PIXEL_BUFFER_SIZE);         //etc. - see above
                    $pixel_buffer_index = 0;
               }
          }
     }
     else {                                                               //unpaletted images
          for ($i = 0; $i < PIXEL_COUNT; $i++) {                          //for each pixel
               $pixel_buffer[$pixel_buffer_index++] = readPHC_nbits(16);  //read both pixel bytes directly from data
                                                                          //stream
               if ($pixel_buffer_index == PIXEL_BUFFER_SIZE) {            //etc.
                    pushColors($pixel_buffer, PIXEL_BUFFER_SIZE);
                    $pixel_buffer_index = 0;
               }
          }
     }
}



/* Wrapping up after all pixels have been processed. If the pixel_buffer_index is not zero, this means the pixel buffer
still has pixels that haven't been written to screen, since we only write to screen when full. In this case, do a final
pixel screen write. Then, report results and show the image as decoded on our web page. */

if ($pixel_buffer_index) pushColors($pixel_buffer, $pixel_buffer_index);    //flush pixel buffer
echo '<p>Decompressed image, RGB565 formatted:';            //report result
imagepng($im, 'decompressed_RGB565_bitmap.png');            //and display decoded image
imagedestroy($im);                                          //remove image resource from memory
echo '<p><img src="decompressed_RGB565_bitmap.png" style="margin-left:70px;width:'.WIDTH.'px;height:'.HEIGHT.'px">';



/* FUNCTIONS:

readPHC_bit() reads individual bits from the image data stream and closely resembles the equivalent function on the
Arduino/ESP8266. Before the first invocation of this function, $bit_index is set to 7 to test the first/highest bit in
the current byte. With each bit read, we decrement the bit pointer until we reach 0, after which we reset to 7. The
function returns 1 if the bit is set, 0 if not.

readPHC_bits() simply uses readPHC_bit to read multiple bits and return the resultant value.

repeatPixel() repeats pixels by writing the last-used two-byte pixel code to the pixel buffer again, as many times as
required.

Function pushColors() is the only one that isn't part of my original code, but is used here to write pixels to screen
the way Bodmer's function does in my Arduino code. */ 

function readPHC_bit() {
     global $bit_index;                                     //current bit position
     global $ptr;                                           //position of the byte currently being read
     global $current_byte;                                  //current byte value
     global $pixel_data_bytes;                              //image data
     global $code_value;                                    //value of bit being read

     if ($bit_index == 7) $current_byte = @$pixel_data_bytes[$ptr++]; 
                                                            //if high bit being read, this requires the next data byte.
                                                            //Read it, store it as $current_byte, and advance pointer 
     $code_value = ($current_byte >> $bit_index) & 1;       //check desired bit in currently defined byte
     if ($bit_index) $bit_index--;                          //decrement bit index if non-zero
     else $bit_index = 7;                                   //if zero, reset bit index to 7
     return $code_value;                                    //return bit value
}
 
 
function readPHC_nbits($n) {                                //read $n bits
     $nbit_value = 0;                                       //initialize value
     while ($n) $nbit_value |= readPHC_bit() << --$n;       //read bits and left shift as needed; add 'em 
     return $nbit_value;
}


function repeatPixel($n) {
     global $pixel_buffer, $pixel_buffer_index, $pixel_bytes;
     
     for ($n; $n > 0; $n--) {                                     //for the number of repeats specified
          $pixel_buffer[$pixel_buffer_index++] = $pixel_bytes;    //copy the pixel bytes to the output buffer

         if ($pixel_buffer_index == PIXEL_BUFFER_SIZE) {          //and if the buffer is full,
              pushColors($pixel_buffer, PIXEL_BUFFER_SIZE);       //write pixels to screen,
              $pixel_buffer_index = 0;                            //reset buffer index
         }
     }
}


function pushColors($pixel_buffer, $n) {                    //writes $n pixels from the buffer to screen
     global $x, $y;
     global $im;                                            //image resource for PHP's gd image library

     $i = 0;                                                //index of pixel in buffer
     while ($i < $n) {                                      //while pixels remain to be written to screen
          $colour = $pixel_buffer[$i++];                    //get pixel colour from buffer
          imagesetpixel($im, $x++, $y, imagecolorallocate($im, $colour >> 11 << 3, (($colour >> 5) & 0x3f) << 2, ($colour & 0x1f) << 3));
                                                            //and write pixel to screen; advance $x with each
          if ($x == WIDTH) {                                //if a full line has been written,
               $x = 0;                                      //reset $x to 0,
               $y++;                                        //advance $y
          }          
     }
}

?>