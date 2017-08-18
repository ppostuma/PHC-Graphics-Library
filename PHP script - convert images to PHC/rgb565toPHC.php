<?php

/*_____________________________________________________________________________________________________________________

Graphic to RGB565 C code for Arduino or similar microprocessors
version 0.30 
August 2016
Paul Postuma and Ars Informatica
www.ars-informatica.ca

This PHP script takes a user-submitted graphic and rewrites it as a tight, RGB565-encoded, optionally compressed PHC
graphics file, or as C code to be read from PROGMEM, to be stored in and read from the code section of an Arduino,
ESP82666, or similar microprocessor's flash memory. 

On the target processor, the generated code requires Bodmer's implementation of Adafruit's GDX graphics library, as
found at https://github.com/Bodmer/TFT_eSPI, as well as my PHC graphics library, at 
https://github.com/ppostuma/PHC-Graphics-Library. Please note that at present, only PHC files as stored in SPIFFS are
supported.

This code intends to fill a need for code that allows images to be stored in the microprocessor's own memory where
space allows, and that is pushed quickly to screen. Windows BMP, GIF, JPG and PNG are supported as input.

Images to more-efficient RGB565 code, encoding colours as 16 bits per pixel rather than 24 or 32 bits per pixel - a
compromise that results in no discernible loss of image quality on the LCD screens typically used with Arduinos or
their derivatives. Transparent or semi-transparent pixels are re-encoded using the user-provided background colour. 
Full-colour images are encoded as paletted if this results in size savings without further loss of colour information. 
Finally, optionally, we compress image information using a custom Run-Length Encoding scheme.

Size savings in testing range from about 15% to 85% over the raw, uncompressed image data as decoded from the source 
image.

I've used the GD graphics library as it's more consistently part of the PHP install on hosted servers than, for 
example, ImageMagick, and it's considerably faster for our purposes.

The script should be relatively easy to read and modify. I have tried hard to maximize readibility and will have over-
annotated for experienced programmers. That said, I have tried not to compromise efficiency, and for code that may have
to be executed tens or hundreds of thousands of times, I have opted for efficiency over clear readability. As such, a
knowledge of bit operations is essential. You'll need to run your own PHP server to use it. "Large" images, i.e. 320 x
240 or more in size, can take seconds to process, depending on the speed of your server. Even larger images may scripts
to time out, or memory to max out - your experience may vary. I've deliberately limited input image size to 1.5 MB but
feel free to modify the code to suit your needs.
_____________________________________________________________________________________________________________________*/



define('MAX_FILESIZE', 1500000);                            //limit image size: prevent script timeouts/memory overruns
define('COMP_CODEC', 'codec PHC.php');                      //link compression codec
define ('PHC_BUFFER_SIZE', 512);                            //decoding CONSTANTS
define ('PIXEL_BUFFER_SIZE', 128);

$background_RGB = array(255, 255, 255);                     //default background colour for images with transparency

$show_palette_code = (@$_POST['show_pal_code']) ? 1 : 0;              //show palette info including intermediate data
$show_image_data = (@$_POST['show_img_data']) ? 1 : 0;                //display pixel index or rgb565 code
$show_code = (@$_POST['show_code']) ? 1 : 0;                          //display generated C code
$disable_compression = (@$_POST['disable_compression']) ? 1 : 0;      //optionally suppress compression beyond simply
                                                                      //packing pixels as tightly as possible
$compression_report_verbose = (@$_POST['comp_rep_verbose']) ? 1 : 0;  //display compression details
$test_decode = (@$_POST['test_decode']) ? 1 : 0;                      //test decompression and display resulting image.
                                                                      //Slow, but useful for testing your encoded image

if (@$_POST['bg-color']) {                                  //if provided, process user-selected background colour
     $hexbg = substr($_POST['bg-color'], 1, 6);             //remove initial '#' character
     $v = hexdec($hexbg);                                   //convert to decimal
     $background_RGB[0] = ($v >> 16) & 0xFF;                //extract red, greeen and blue colour values
     $background_RGB[1] = ($v >> 8) & 0xFF;
     $background_RGB[2] = $v & 0xFF;
}
else $hexbg = sprintf('%02x%02x%02x', $background_RGB[0], $background_RGB[1], $background_RGB[2]);  //if no alternate
                                                            //colour was provided, use the default $background_RGB.
                                                            //Convert to string for use with HTML form, below

$r = $background_RGB[0] >> 3 << 11;                         //for the background colour, convert the 8-bit red value to
                                                            //a 5-bit value, then shift left to occupy the first 5 of
                                                            //16 bits in a 2-byte RGB565 value
$g = $background_RGB[1] >> 2 << 5;                          //convert green to a 6-bit value and left shift 5 bits to
                                                            //occupy bits 6-11 of the RGB565 value
$b = $background_RGB[2] >> 3;                               //and do the same for blue
$background_RGB565 = $r | $g | $b;                          //combine colour components and save



/* Analyze submitted image file - acceptable types being JPG, GIF, PNG, Windows BMP

The GD library function imagecreatefrom() handles the first three formats but does not process Windows BMP - this will
require our own code. Basic image parameters are returned: file name and size, the file's suffix, image data, and basic
info where reported by GD */

if(isset($_FILES['image_file'])) {                          //process POSTed image file
     $file_types = array('image/jpeg', 'image/jpg', 'image/gif', 'image/png', 'image/bmp');
     $fsize = $_FILES['image_file']['size'];
     
     if (!in_array($_FILES['image_file']['type'], $file_types) || !@exif_imagetype($_FILES['image_file']['tmp_name'])) $err = 'Invalid file type. Only Windows BMP, JPG, GIF and PNG types are accepted.';
     else if (($fsize >= MAX_FILESIZE) || ($fsize == 0)) $err = 'Invalid file size. Only image files of '.MAX_FILESIZE.' or less will be processed.';
     else if (!@$err) {
          $fname = $_FILES['image_file']['name'];
          if ($_FILES['image_file']['type'] == 'image/gif') $im = imagecreatefromgif($_FILES['image_file']['tmp_name']) or $err = 'Unable to open GIF';
          else if ($_FILES['image_file']['type'] == 'image/png') $im = imagecreatefrompng($_FILES['image_file']['tmp_name']) or $err = 'Unable to open PNG';     
          else if ($_FILES['image_file']['type'] == 'image/jpg') $im = imagecreatefromjpeg($_FILES['image_file']['tmp_name']) or $err = 'Unable to open JPG';
          else if ($_FILES['image_file']['type'] == 'image/jpeg') $im = imagecreatefromjpeg($_FILES['image_file']['tmp_name']) or $err = 'Unable to open JPG';
          else if ($_FILES['image_file']['type'] == 'image/bmp') {
               move_uploaded_file($_FILES['image_file']['tmp_name'], 'temp_img/'.$fname);
               $file = file_get_contents('temp_img/'.$fname);
               unlink('temp_img/'.$fname);
          }
          @$finfo = getimagesize($_FILES['image_file']['tmp_name']);        //store image size
          $fsuffix = substr($fname, -3);                                    //parse out file type
          $codefname = 'RGB565_'.ucwords(substr($fname, 0, -4)).'BMP';      //create filename string for data output
          $code = '';                                                       //and initialize the output code
    } 
}



/* Initial HTML code - describes basic code function; allows selection of a background colour for converting 
transparent images; allow various code display parameters to be changed with next image upload. For curiosity mostly 
but also Useful for debugging */

echo '<style type="text/css">span { display:inline-block; width:400px; }</style>

<h2>Image to RGB565 Code Converter</h2>

<p>Converts GIF, JPG, PNG and Windows BMP images to PHC files/C code for use with TFT LCD screens, as with Arduino or its derivatives. Uses a somewhat more limited but space-saving RGB565 palette that works well with these screens.

<form id="upload_image" enctype="multipart/form-data" method="post" action="RGB565toPHC.php">
<input type="hidden" name="MAX_FILE_SIZE" value="'.MAX_FILESIZE.'" />
<p>Upload file: <input type="file" ID="f" name="image_file" id="image_file" style="border:1px solid #ccc" onChange="checkUpload()" /> &nbsp; 
Background colour for transparent images: <input name="bg-color" type="color"  value="#'.$hexbg.'" /> (default: white)
<p><span><input name="show_pal_code" type="checkbox" ';
if ($show_palette_code) echo 'checked ';
echo '/> Show palette codes (for paletted images)</span>
<span><input name="disable_compression" type="checkbox" ';
if ($disable_compression) echo 'checked ';
echo '/> Disable compression (not recommended)</span><br>
<span><input name="show_img_data" type="checkbox" ';
if ($show_image_data) echo 'checked ';
echo '/> Show image data, pixel by pixel</span>
<span><input name="comp_rep_verbose" type="checkbox" ';
if ($compression_report_verbose) echo 'checked ';
echo '/> Report compression details</span><br>
<span><input name="show_code" type="checkbox" ';
if ($show_code) echo 'checked ';
echo '/> Show C code</span>
<span><input name="test_decode" type="checkbox" ';
if ($test_decode) echo 'checked ';
echo '/> Test decode image to verify (takes longer)</span>

<p><input type="submit" value="Upload" onsubmit="return checkUpload()" />
</form>
<hr />
 
<script>
function checkUpload() {
     re = (/\.(bmp|gif|jpg|jpeg|png)$/i);                             //regular expression check - does file to be uploaded have a valid file suffix?
     if (!re.test(document.getElementById("f").value)) {
          alert("Valid image file not detected. Please try again");
          return false;
     }
     else return true;
}
</script>';



//check for and report fatal errors

if (!@$fname && !@$err) $err = 'Please upload a valid image file.';
if (@$err) {
     echo "<p>$err";
     die;
}



/* Load image stream and display basic information. Easier but not entirely accurate for images using the GD library:
GIF information is complete and accurate, but JPG and PNG only report bit depth per colour channel, not per pixel, and
number of channels is not reported for PNG. So a full-colour PNG will report a depth of 8 bits, even if there are three
or four colour channels defined. Fortunately, imagecreatefrompng() returns 32-bit data regardless of whether the file
uses 24 or 32 bits per pixel - so that's what we use here.

Use the number of colours reported for the image to differentiate full-colour from paletted images, as the GD functions
do consistently report this number as 0 for unpaletted images.

Finally, the GD library does not process Windows BMP files. The documentation appears to suggest that it does - but it 
handles only low-bandwith WBMP or Wireless Bitmaps, used to render black and white images on older PDAs and mobile
phones, not Windows BMP files. These we decode manually. Note that the routine below handles the vast majority of BMP
files, but I've not bothered with those rarely used variants that incorporate one of at least nine different forms of
internal compression */

if ($fsuffix != 'bmp') {                                    //process basic non-BMP data using the GD library
     $finfo = getimagesize($fname);                         //getimagesize() gives image width, height and bit depth
     define('WIDTH', $finfo[0]);                            //save width and height as constants (saves us from passing
                                                            //explicitly to functions)
     define('HEIGHT', $finfo[1]);
     $bit_depth = $finfo['bits'];                           //bit depth we'll manipulate, so save as variable
     $img_colour_count = imagecolorstotal($im);

     if ($fsuffix == 'png' && $img_colour_count == 0) $bit_depth = 32;  //GD's getimagesize does not report PNG's pixel
                                                                        //bit depth, but uses 32 bits/pixel for
                                                                        //unpaletted PNG files
     else if ($fsuffix == 'jpg') $bit_depth = 24;           //same for JPG, but returns 24 bits/pixel (no transparency
}                                                           //channel)
          
else {
     $file = file_get_contents($fname);                     //parse Windows BMP file - GD library does not
     $a = unpack("H*", $file);                              //convert binary data to hex-encoded string
     $hex_string = $a[1];                                   //only second part of this array contains the hex string
     unset($file);                                          //unset larger memory structures we do not need; script can
     unset($a);                                             //be quite memory-intensive
     $header = substr($hex_string, 0, 108);                 //header data = 54 bytes, 2 hex characters per byte
        
     if (substr($header, 0, 4) != "424d") die("Not a valid BMP file");

     $header_bytes = str_split($header, 2);                             //split header string into 2-hex chars/1-byte
                                                                        //chunks
     define('WIDTH', hexdec($header_bytes[19].$header_bytes[18]));      //width and height take up to four bytes; read
                                                                        //only two (sufficient for images up to 65535
                                                                        //pixels wide)
     define('HEIGHT', hexdec($header_bytes[23].$header_bytes[22]));     //same for height
     $bit_depth = hexdec($header_bytes[28]);                            //read only one byte for bit depth up to 32
     $img_colour_count = hexdec($header_bytes[47].$header_bytes[46]);   //read only 2 bytes for colour since max is
                                                                        //256; full-colour images return 0
     $img_data_offset = hexdec($header_bytes[11].$header_bytes[10]);    //read the first two of four bytes for offset
     unset($header_bytes);
     unset($header);
}



/* Report image parameters back to user. Calculate number of colours in palette or report that image is encoded as
full-colour. Determine size of the raw image data for either a true-colour image, or a paletted one */

echo "<p>Valid bitmap file $fname detected<br>
File size: ".number_format($fsize).' bytes<br>
Width: '.WIDTH.' px by height '.HEIGHT." px<br>
Colour or palette index bit depth: $bit_depth<br>
Number of paletted colours: $img_colour_count";

define('PIXEL_COUNT', WIDTH * HEIGHT);                      //calculate number of pixels
if (!$img_colour_count) {                                   //no image colour count: true- or full-colour image
     echo  ' (true colour image)';
     $fsize = PIXEL_COUNT * $bit_depth >> 3;                //size of raw data for unpaletted images in bytes
}
else $fsize = $img_colour_count * 3 + PIXEL_COUNT * $bit_depth >> 3;    //else raw data size: three bytes per colour,
                                                                        //plus one index byte per pixel
echo '<br>Size of uncompressed image data: '.number_format($fsize).' bytes';



/* Process unpaletted or 'true-colour' image data. For non-Windows Bitmap files, we directly convert each pixel's 
colour value (an integer returned by function imagecolorat) to RGB565 format using bit ops. In testing, this was almost
always significantly faster than storing this colour value to an array, the colours used to a second array, computing
the RGB565 value for each colour once, then replacing the old value with the new one in the pixel array.

For Windows BMP files, however, in testing the extra hexdec() conversion makes it faster to store the pixel's colour
value (a string) to an array, store each colour string value and convert these to RGB565 once only, then replace the
pixel string values with their matching RGB565 codes. */

if ($img_colour_count == 0) {                                           //full-colour or true-colour images
     if ($fsuffix != 'bmp') {                                           //non-BMP files
          if ($bit_depth == 32)                                         //with potential transparency
            for ($row = 0; $row < HEIGHT; $row++) for ($i = 0; $i < WIDTH; $i ++) {   //for each pixel
               $v = imagecolorat($im, $i, $row);                        //get the pixel value at a given position
               if ($alph = $v >> 24) {                                  //if an alpha channel value has been set, this
                                                                        //defines the pixel's transparency. If 0, it's
                                                                        //fully opaque; no blending required
                    if ($alph == 127) {                                 //if the maximum that imagecolorat() returns
                         $v = $pixel_bytes[] = $background_RGB565;      //that pixel takes on the background colour
                    }
                    else {                                              //else determine the pixel's colour
                         $opac = 127 - $alph;                           //calculate opacity, from 0 to 127
                         $r = ((($v >> 16) & 0xFF) * $opac + $background_RGB[0] * $alph) >> 10 << 11;
                                                                        //calculate red value from the colour's byte
                                                                        //value and its opacity, plus the background
                                                                        //colour's contribution. Shift 10 bits to the
                                                                        //right to convert to 5-bit value; shift left
                                                                        //11 for the first 5 of 16 bits in a 2-byte
                                                                        //RGB565 value
                         $g = ((($v >> 8) & 0xFF) * $opac + $background_RGB[1] * $alph) >> 9 << 5;    //same for green
                         $b = (($v & 0xFF) * $opac + $background_RGB[2] * $alph) >> 10;               //etc.
                         $v = $pixel_bytes[] = $r | $g | $b;            //sum colour components and assign each pixel
                                                                        //value to array $pixel_bytes
                     }
               }
               else {                                       //for non-transparent pixels
                    $r = ($v >> 16) >> 3 << 11;             //get the pixel's 8-bit red value, convert to 5 bits, then
                                                            //shift left for the first 5 of 16 bits in a 2-byte RGB565
                                                            //value
                    $g = (($v >> 8) & 0xFF) >> 2 << 5;      //convert green value to 6 bits and left shift 5 bits for
                                                            //bits 6-11 of the RGB565 value
                    $b = ($v & 0xFF) >> 3;                  //etc.
                    $v = $pixel_bytes[] = $r | $g | $b;     //sum colour components and assign to $pixel_bytes
               }
               if (!@$rgb[$v]) $rgb[$v] = 1;                //store colour as key in an array, with a value of 1 the
                                                            //first time it is found   
               else $rgb[$v]++;                             //and increment value each time after it is encountered
          }
          
          else for ($row = 0; $row < HEIGHT; $row++) for ($i = 0; $i < WIDTH; $i++) {   
                                                            //for each non-transparent pixel
               $v = imagecolorat($im, $i, $row);            //get pixel value
               $r = ($v >> 16) >> 3 << 11;                  //convert to 5-bit value
               $g = (($v >> 8) & 0xFF) >> 2 << 5;           //etc.
               $b = ($v & 0xFF) >> 3;
               $v = $pixel_bytes[] = $r | $g | $b;          //combine colours into 2-byte RGB565 code
               if (!@$rgb[$v]) $rgb[$v] = 1;                //log colour's first occurrence in array $rgb
               else $rgb[$v]++;                             //or increment the count for this colour
          }
          
          imagedestroy($im);                                //after every pixel has been read, release the memory used
     }                                                      //by the GD image function
     
     else {                                                           //full-colour Windows BMP files
          $img_data = substr($hex_string, $img_data_offset * 2);      //get the image pixel data from the hex string
          unset($hex_string);                                         //remove unneeded string from memory
          $hex_data_per_row = ($bit_depth >> 2) * WIDTH;              //bit depth >> 3 gives bytes per pixel; << 1
                                                                      //converts to hex characters per pixel
          $row_length = ($hex_data_per_row + 7) >> 3 << 3;            //image row length's worth of BMP data: round up
                                                                      //to nearest 4 bytes or 8 hex chars

          for ($row = 0; $row < HEIGHT; $row++) {                     //for each row of pixels in the image
               $v = substr($img_data, 0, $hex_data_per_row);          //read one row's worth of data
               $img_data = substr($img_data, $row_length);            //and remove it from the source string
               $row_bytes[$row] = str_split($v, $bit_depth >> 2);     //split per pixel: $bit_depth / 8 bits per byte;
          }                                                           //2 hex chars/byte 
          $row_bytes = array_reverse($row_bytes);                     //reverse the array as the BMP format starts with 
                                                                      //the bottom row
 
          for ($row = 0; $row < HEIGHT; $row++) foreach ($row_bytes[$row] as $v) {    //for each pixel, i.e. a string
                                                                      //value of 6 or 8 hex chars
               $pixel_bytes[] = $v;                                   //save value to array $pixel_bytes
               if (!@$rgb[$v]) $rgb[$v] = 1;                          //save colour to array $rgb to convert later
               else $rgb[$v]++;                                       //while counting each occurrence of the colour
          }
          unset($row_bytes);                                          //then remove array $row_bytes from memory

          if ($bit_depth == 32) foreach ($rgb as $k => &$v) {         //convert each 32-bit colour to RGB565 only once,
                                                                      //using array $rgb
               $v = hexdec($k);                                       //convert hex string to integer
               if (($opac = $v & 0xFF) != 255) {                      //the first byte of a 32-bit BMP value is for
                                                                      //opacity, not transparency!
                    $alph = 255 - $opac;                              //calculate transparency value, from 0 to 255
                    $r = ((($v >> 8) & 0xFF) * $opac + $background_RGB[0] * $alph) >> 11 << 11;   //calculate red value
                                                                      //as we did for non-BMPs, above
                    $g = ((($v >> 16) & 0xFF) * $opac + $background_RGB[1] * $alph) >> 10 << 5;   //etc.
                    $b = ((($v >> 24) & 0xFF) * $opac + $background_RGB[2] * $alph) >> 11;
               }
               else {                                                 //if the pixel's opacity is 255 i.e. fully
                                                                      // opaque, ignore transparency
                    $r = (($v >> 8) & 0xFF) >> 3 << 11;
                    $g = (($v >> 16) & 0xFF) >> 2 << 5;
                    $b = (($v >> 24) & 0xFF) >> 3;                    
               }
               $v = $r | $g | $b;                           //combine RGB values into a 2-byte RGB565 value, and save
          }                                                 //as new array key
          else foreach ($rgb as $k => &$v) {                //process 24-bit colours as above, without
                                                            //worrying about transparency
               $v = hexdec($k); 
               $r = (($v) & 0xFF) >> 3 << 11;
               $g = (($v >> 8) & 0xFF) >> 2 << 5;
               $b = (($v >> 16) & 0xFF) >> 3;
               $v = $r | $g | $b;
          }     
          unset($v);                                        //unset $v or could get last palette key rewritten
                                                            //inadvertently, later

          foreach ($pixel_bytes as &$v) $v = $rgb[$v];      //for each pixel value, rewrite it as its RGB565 colour
                                                            //value, from $rgb
          unset($v);                                        //unset $v to prevent $pixel_bytes last value from being 
                                                            //rewritten
          $rgb = array_flip($rgb);                          //flip colours to a smaller array (a palette!) containing
                                                            //each RGB565 value only once  
     }
     
     $pixel_byte_count = count($pixel_bytes) * 2;           //count number of bytes currently used for pixels



/* Count number of colours used in image; if less than 256 and size as a paletted image is smaller than unpaletted, 
convert to paletted. */

     $img_colour_count = count($rgb);                       //count number of RGB565 colours
     for ($i = 1; (1 << $i) < $img_colour_count; $i++);     //determine most significant bit, or bit depth of palette
                                                            //values
     $paletted_byte_count = $img_colour_count * 2 + PIXEL_COUNT * $i / 8;   //size paletted
     $bytes_saved = intval($pixel_byte_count - $paletted_byte_count);       //size over unpaletted
     

     if ($bytes_saved > 0) {
          if ($img_colour_count > 256) {                    //small palettes make sense. When more than 256 colours
                                                            //are used, palettes get large and inefficient
               define('BIT_DEPTH', 16);                     //so simply store each pixel at 16 bits per pixel
               echo "<p>Image uses $img_colour_count unique colours at 16-bit RGB565 colour depth, for $pixel_byte_count bytes in C code, before compression. Consider converting to a paletted image file using 256 or fewer colours before running this script, for significantly smaller bitmaps. <a href onClick=\"document.getElementById('decreaseNotice').style.display = 'block'; return false\">[ <u>more</u> ]</a>".'<div id="decreaseNotice" style="display:none"><p>To decrease colour depth, use your favourite paint program, IrfanView, or - my preferred option - a standalone tool like RIOT (Radical Image Optimization Tool) from <a href="http://luci.criosweb.ro/riot/">http://luci.criosweb.ro/riot/</a> with either the Xiaolin Wu or NeuQuant quantizers. For maximum image size reduction, go with the lowest of 256, 128, 64, 32, 16, 8, 4 or 2 colours that still delivers the image quality you want.</div>';
               $img_colour_count = 0;                       //encode as RGB565 "true-colour"
               unset($rgb);                                 //remove the potentially huge, unnecessary palette from memory
          }
          else {                                            //for less than 256 colours, palettes typically produce
                                                            //smaller/faster code
               define('BIT_DEPTH', $bit_depth = $i);        //save bit depth of each palette entry - $i as calculated
                                                            //above
               echo "<p>Image uses $img_colour_count unique colours at 16-bit RGB565 colour depth, for $pixel_byte_count bytes in C code. Converting to a paletted image file.";
          }
     }
     
     else {                                                 //if paletting does not produce size savings
          define('BIT_DEPTH', 16);                          //encode as 16 bits per pixel
          if ($img_colour_count > 256) echo "<p>Image uses $img_colour_count unique colours at 16-bit RGB565 colour depth, for $pixel_byte_count bytes in C code, before compression. Consider converting to a paletted image file of 256 or fewer colours before running this script, for smaller bitmap sizes. <a href onClick=\"document.getElementById('decreaseNotice').style.display = 'block'; return false\">[ <u>more</u> ]</a>".'<div id="decreaseNotice" style="display:none"><p>To decrease colour depth, use your favourite paint program, IrfanView, or - my preferred option - a standalone tool like RIOT (Radical Image Optimization Tool) from <a href="http://luci.criosweb.ro/riot/">http://luci.criosweb.ro/riot/</a> with either the Xiaolin Wu or NeuQuant quantizers. For maximum image size recuction, go with the lowest of 256, 128, 64, 32, 16, 8, 4 or 2 colours that still delivers the image quality you want.</div>';
          else echo "<p>Image uses $img_colour_count unique colours at 16-bit RGB565 colour depth, for $pixel_byte_count bytes in C code.";
          $img_colour_count = 0;                            //save as "true-colour"
          unset($rgb);                                      //and remove palette from memory
     }
}



/* Process paletted GIF, JPG, PNG and finally Windows BMP image files. Decode and optionally display palette entries,
convert to RGB565 */

else {
     if ($fsuffix != 'bmp') {                               //GIF, JPG and PNG:
          for ($i = 0; $i < $img_colour_count; $i++) {      //for each colour in the palette
               $a = imagecolorsforindex($im, $i);           //load colour channel values into array
               if (array_key_exists('alpha', $a)) {         //if alpha value has been defined
                    $alph = $a['alpha'];                    //save transparency value as $alph
                    $opac = 127 - $alph;                    //calculate opacity value
                    $r = ($a['red'] * $opac + $background_RGB[0] * $alph) >> 10 << 11;    //calculate red value, 
                                                                                          //exactly as before
                    $g = ($a['green'] * $opac + $background_RGB[1] * $alph) >> 9 << 5;    //etc.
                    $b = ($a['blue'] * $opac + $background_RGB[2] * $alph) >> 10;
                    $v = $r | $g | $b;
                    if ($show_palette_code) {               //if user asks for source palette information
                         $src_pal_RGBA[$i] = sprintf('%02x%02x%02x%02x', $a['red'], $a['green'], $a['blue'], $a['alpha']);
                                                            //save as both 32-bit information
                         $src_pal_RGB[$i] = sprintf('%02x%02x%02x', $r >> 8, $g >> 3, $b << 3);   //and as 24-bit
                    }
               }
               else {                                       //if transparency has not been defined
                    $v = $a['red'] >> 3 << 11 | $a['green'] >> 2 << 5 | $a['blue'] >> 3;  //just process
                    if ($show_palette_code) $src_pal_RGB[$i] = sprintf('%02x%02x%02x', $a['red'], $a['green'], $a['blue']); 
               }
               @$rgb[$v] = 0;                               //save RGB565 value to palette array $rgb
               $pixel_index[$i] = $v;                       //and define this as the colour for pixel index $i
          }   

          for ($row = 0; $row < HEIGHT; $row++) for ($i = 0; $i < WIDTH; $i++) {    //process pixels by row and column
               $v = $pixel_bytes[] = imagecolorat($im, $i, $row);     //for each, retrieve index and store in array
               $rgb[$pixel_index[$v]]++;                              //increment the palette array value to count how
          }                                                           // often the colour is used
          
          imagedestroy($im);                                //remove image asset from memory when no longer needed
          
          foreach ($pixel_bytes as &$v) $v = $pixel_index[$v];        //convert index to its colour value - multiple
                                                            //source colours will map to one RGB565 colour. This step
                                                            //helps to remove duplicate indices per colour
          unset($v);                                        //unset variable or its next use will update the last value
                                                            //in the pixel array
          unset($pixel_index);                              //and remove array $pixel_index as no longer needed
     }
     
     else {                                                           //process BMP images
          $palette = substr($hex_string, 108, $img_colour_count * 8); //get palette hex string: 4 bytes per colour; 2
                                                                      //hex characters per byte
          $colours = str_split($palette, 8);                          //so split on 8 hex character segments
          unset($palette);                                            //remove unnecessary variable from memory
          for ($i = 0; $i < $img_colour_count; $i++) {                //for each colour in the palette
               $v = hexdec($colours[$i]);                             //get its hex value
               $r = ($v >> 8) & 0xFF;                                 //bit shift 8 to get red
               $g = ($v >> 16) & 0xFF;                                //etc.
               $b = ($v >> 24) & 0xFF;
               $v = ($r >> 3 << 11) | ($g >> 2 << 5) | ($b >> 3);     //save as RGB565
               $pixel_index[$i] = $v;                                 //define as the colour for pixel index $i
               if ($show_palette_code) $src_pal_RGB[] = sprintf('%02x%02x%02x', $r, $g, $b);   //if desired, save the
          }                                                           //source palette value    

          $img_data = substr($hex_string, $img_data_offset * 2);      //next, get pixel data from the source hex code
          unset($hex_string);                                         //remove large variables when no longer needed
          $hex_data_per_row = ($bit_depth * WIDTH) >> 2;              //bit depth >> 3 gives bytes per pixel; << 1
                                                                      //converts to hex characters per pixel
          $hex_chars_per_row = ($hex_data_per_row + 7) >> 3 << 3;     //image row length's worth of BMP data: round up
                                                                      //to nearest 4 bytes or 8 hex chars

          if ($bit_depth == 8) $split_var = 2;                        //at bit depth of 8, split on 2 hex chars
          else if ($bit_depth == 4) $split_var = 1;                   //at bit depth of 4, split on single hex chars

          if ($bit_depth == 4 || $bit_depth == 8)                     //4 and 8-bit BMPs:
            for ($row = 0; $row < HEIGHT; $row++) {                   //because of padding, read one row at a time
               $v = substr($img_data, 0, WIDTH * $bit_depth);         //read one row's worth of data - ignores padding
               $img_data = substr($img_data, $hex_chars_per_row);     //remove row from image data string, with padding
               
               $a = str_split($v, $split_var);              //split row's data into 1- or 2-hex char segments; 4 or 8
                                                            //bits each
               for ($i = 0; $i < WIDTH; $i++) {             //for each pixel in the row
                    $v = $pixel_index[hexdec($a[$i])];      //get its colour by its pixel index
                    if (!@$rgb[$v]) $rgb[$v] = 1;           //and save this to the new palette array
                    else $rgb[$v]++;                        //increment number of times colour occurs 
                    $row_bytes[$row][$i] = $v;              //finally, store colour value for this pixel
               }
          }
          else for ($row = 0; $row < HEIGHT; $row++) {                //for paletted BMPs with neither 4 nor 8 bit
                                                                      //pixels, i.e. single-bit/2-colour images
               $v = substr($img_data, 0, WIDTH * $bit_depth);         //read one row's worth of data
               $img_data = substr($img_data, $hex_chars_per_row);     //and remove from image data string
               
               $a = str_split($v, 4);                       //at bit depths other than 4 or 8, split on 2 bytes or 4
                                                            //hex chars, since BMP rows are always multiples of these 

               $bits = array();                             //convert each 2 bytes into an integer; read bit by bit
               foreach ($a as $v) {                         //read 2-byte chunks from array $a
                    $v = hexdec($v);                        //convert to decimal
                    for ($j = 15; $j >= 0; $j--) $bits[] = ($v >> $j) & 1;    //starting with the first or most 
               }                                            //significant bit, read all bits into $bits
               
               for ($i = 0; $i < WIDTH; $i++) {             //for each pixel in the row,
                    $v = $pixel_index[$bits[$i]];           //get colour from its index value
                    $row_bytes[$row][$i] = $v;              //store colour value for this pixel position
                    if (!@$rgb[$v]) $rgb[$v] = 1;           //save colour to new palette array
                    else $rgb[$v]++;                        //and increment if used more than once
              }
          }

          unset($pixel_index);                              //remove now-unnecessary arrays from memory
          unset($bits);
          $row_bytes = array_reverse($row_bytes);           //reverse rows as BMP files are read from bottom to top
          for ($row = 0; $row < HEIGHT; $row++)             //copy pixels by row 
            foreach ($row_bytes[$row] as $v) $pixel_bytes[] = $v;     //to a simple array of pixel bytes
          unset($row_bytes);
     }           

     $img_colour_count = count($rgb);                       //determine number of unique colours used
     for ($i = 0; (2 << $i) < $img_colour_count; $i++);     //determine most significant bit, or bit depth of palette
     define('BIT_DEPTH', $bit_depth = ++$i);                //save as constant
}
  


/* Parse the palette. Show the source palette codes in hex-encoded RGB/RGBA format, then the derived RGB565 codes. 
Usually there are fewer of the latter, as least significant data bits are discarded in producing a 2-byte RGB565 
version, as is any transparency information. Then index the new RGB565 palette and update all pixels to refer to these
new index values. */

if (BIT_DEPTH != 16) {                                      //non-16-bit or paletted images:
     $pal_string = '';                                      //initialize code for palette information
     if ($show_palette_code) {                              //if user asked for palette information to be displayed
          echo '<p>Palette code:<pre>';                     //do so

          if (@$src_pal_RGBA) {                             //32-bit RGBA source palette information, i.e. images with
                                                            //an alpha or transparency channel
               echo '<p>RGBA32<p>';
               foreach ($src_pal_RGBA as $v) echo "$v ";    //show each 32-bit palette entry
          }
          if (@$src_pal_RGB) {
               echo '<p>RGB<p>';
               foreach ($src_pal_RGB as $v) echo "$v   ";   //show palette values in 24-bit format; transparency, if 
                                                            //any, has been processed in
          }
          else echo 'Source image is unpaletted. New RGB565 palette generated.';

          echo '<p>RGB565, duplicates and unused values removed
Colour count: '.$img_colour_count.'<br>Bit  depth: '.BIT_DEPTH.'<p>';   //summarize result

          foreach ($rgb as $k => $v) {                      //for each RGB565 palette entry
               $pal_string .= chr($k >> 8);                 //convert to char code, append to palette string for later
               $pal_string .= chr($k & 255);
               echo ($palette_bytes[] = sprintf('%04x', $k)).'     ';   //display value in C code, and save it to array
                                                                        //$palette_bytes
               $table[] = sprintf('<td style="background-color:#%02x%02x%02x">&nbsp;</td>', $k >> 11 << 3, (($k >> 5) & 0x3f) << 2, ($k & 0x1f) << 3);              
          }                                                             //convert value to HTML colour definition and
                                                                        //save as a coloured table cell
          echo '</pre>
       
<style type="text/css">
.tg { border-collapse:collapse; border-spacing:0; margin-left:70px}
.tg td { width:25px; height:20px; border-style:solid; border-width:1px }
pre { white-space:pre-wrap }
</style>
<table class="tg">';
        
          for ($i = 0; $i < $img_colour_count; $i++) {      //process all palette colours
                if (($i & 15) == 0) echo '<tr>';            //define a new table row every 16 values
                echo $table[$i];                            //display coloured table cell for each palette value
                if (($i & 15) == 15) echo '</tr>';          //terminate the row
          }
          echo '</table>';                                  //and wrap it up
          unset($table);                                    //remove array from memory
     }
     
     else foreach ($rgb as $k => $v) {                      //even if not showing palette codes, palette still needs 
                                                            //processing
          $pal_string .= chr($k >> 8);                      //do as above
          $pal_string .= chr($k & 255);          
          $palette_bytes[] = sprintf('%04x', $k);
     }

     unset($src_pal_RGBA);                                  //remove source palettes
     unset($src_pal_RGB);

     $i = 0;                                                //re-index palette colours, starting with 0
     foreach ($rgb as &$v) $v = $i++;                       //replace colour counts with new, incrementing values
     unset($v);                                             //unset $v so subsequent operations do not replace the last
                                                            //$rgb value
     foreach ($pixel_bytes as &$v) $v = @$rgb[$v];          //set each pixel to its new index value
     unset($v);

     $rgb = array_flip($rgb);                               //flip $rgb to create an array of palette indices with 
                                                            //their colours

     $code = 'const uint16_t '.$codefname.'_Palette[] PROGMEM = {
   0x'.join (', 0x', $palette_bytes).' };'."\n\n";          //create palette PROGMEM code
   
     unset($palette_bytes);                                 //and remove array from memory

     $v = count($pixel_bytes) * BIT_DEPTH / 8;              //count number of bytes in paletted pixel data
     $pixel_byte_count = ($v + 1) >> 1 << 1;                //and round up to nearest even or 2-byte value
}
     


/* Display image data by row, if specified by user: specifically, each pixel's value, either an RGB565 colour value, or
the pixel index value. */

if ($show_image_data) {                                     //if user asks for pixel image data to be displayed
     echo '<p>Image Data:<p><pre style="white-space:nowrap">';
     $x = 1;                                                //initialize pixel position in row
     foreach ($pixel_bytes as $v) {
          echo $v.' ';                                      //display pixel value
          if ($x == WIDTH) {                                //for the last pixel in the row,
               echo '<br>';                                 //append a line break
               $x = 1;                                      //and reset pixel position
          }
          else $x++;                                        //update pixel position
     }
     echo '</pre>';
}



/* Report number of colours and bit depth of PHC image */

echo '<p>Encoded ';
if ($img_colour_count != 0) "using $img_colour_count colours ";
echo 'at a bit depth of '.BIT_DEPTH.' bits.';



/* Create pixel bit stream using the output buffer - tests faster than using join(). Because we're usually packing 
pixels on non-byte boundaries, we'll need to work with a raw bit stream. This uses considerably more memory in the PHP
script but allows us to pack pixels much more efficiently, and saves a great deal of memory on the target processor.

Note that this "bit stream" is not an actual bit stream, but a text string of 0s and 1s interspersed with a few other
control characters. The compression codec uses regular expressions and their associated functions to determine optimal
compression strategies - and these operate on strings.

The compression codec requires a delimiter to separate one pixel from the next; I use a single whitespace " " character
for easy readability when examining the bit stream directly. If compression is disabled, create a bit stream without
delimiters. Function sprintf() produced the fastest format conversion with leading zero padding of several methods
tested, as well as the cleanest code. */

ob_start();
if ($disable_compression == 0) foreach ($pixel_bytes as $v) echo sprintf('%0'.BIT_DEPTH.'b ', $v);  //the space after
                                                                                //'b' is required by our codec
else foreach ($pixel_bytes as $v) echo sprintf('%0'.BIT_DEPTH.'b ', $v);
$pixel_bit_stream = ob_get_clean();


/* If not disabled, link the compression codec. This produces a compressed bit stream $comp0 and copies it to 
$pixel_bit_stream if compression is successful. Either way, report the number of bytes used for the final C code - 2
bytes per paletted colour, plus bytes used by pixel data, 12 bytes for pointers, width, height, bit depth, and 
compression variables. */

if ($disable_compression == 0) include COMP_CODEC;          //link compression codec if not disabled
if (@$comp0) {                                              //check if a compressed bit stream has been produced
     echo '<p>Final size: '; 
     $total_byte_count = $img_colour_count * 2 + $pixel_byte_count + 20;    //get its size and report
     echo number_format($total_byte_count).' bytes. Bytes used for pixel data: '.number_format($pixel_byte_count);
     $pixel_bit_stream = $comp0;                            //copy compressed bits to $pixel_bit_stream
     unset($comp0);                                         //and unset compressed data stream
}

else {                                                      //if no compressed data stream, report as above
     echo '<p>Final size: ';
     $total_byte_count = $img_colour_count * 2 + $pixel_byte_count + 20;
     echo number_format($total_byte_count).' bytes. Bytes used for pixel data: '.number_format($pixel_byte_count);
     $pixel_bit_stream = str_replace(' ', '', $pixel_bit_stream);     //remove white space from bit stream
     $i = strlen($pixel_bit_stream) & 0xf;                            //count bits in the data stream, then determine
                                                                      //how many exceed a full 2-byte (16-bit) value
     if ($i != 0) {                                                   //if there's a remainder
          $i = 16 - $i;                                               //calculate bits to pad out to 2 full bytes
          $pixel_bit_stream .= sprintf('%0'.$i.'b', '');              //and pad out the stream with zeroes
     }
}


/* Check number of bytes - if more than 32,768 then this the exceeds what PROGMEM can hold (as a single structure) - so
suggest alternatives. Note that this limit is irrelevant for PHC files stored in SPIFFS. */

if ($total_byte_count > 32768) echo '<p><b>This image exceeds the 32,768 byte limit for a single PROGMEM structure.</b> It will still work as a PHC file. For PROGMEM usage, consider converting to a paletted file of fewer colours, and trying again. <a href onClick="document.getElementById(\'PROGMEMNotice\').style.display = \'block\'; return false">[ <u>more options</u> ]</a>
<div id="PROGMEMNotice" style="display:none">
<ul><li>decrease colour depth using image-editing software or a standalone tool like <a href="http://luci.criosweb.ro/riot/">RIOT (Radical Image Optimization Tool)</a> with the Xiaolin Wu or NeuQuant quantizers. Go with the lowest of 256, 128, 64, 32, 16, 8, 4 or 2 colours that delivers the image quality you want.
<li>use a smaller image with borders created using Adafruit\'s fillRect() for the background.
<li>break the image into tiled images of less than 32,768 bytes each, so long as you leave enough room for anything else you want in PROGMEM - this maxes out at 65536.
<li>store the image in program flash ROM beyond the 64K limit and access it using pgm_read_byte_far and related functions. I don\'t recommend this, and have not written a function for it. The pgmspace.h library can be buggy past the lowest 64K, and larger files take considerably longer to decode.
<li>create a smaller image and scale it up. It should be easy to modify my Arduino RGB565 function to do so, at least using simple integer multipliers, though this would look blocky. Creating a function that uses interpolation for smoother transitions, or for non-integer scaling would take more work.
</ul></div>';



/*code to create a file of *.PHC format

Byte 10, 11 - width
     12, 13 - height
     14     - bit depth; 16 is unpaletted (as RGB565 is a 16-bit format)
     15, 16 - previously used to store index of most-used colour; now unused
     17     - compression parameters: bit 0 set to one if compression applied, zero if not; bits 1-3 number of repeats,
              bits 4-6 length of read-forward offset used for compression
     18, 19 - gives offset for start of pixel bytes; if 0 then no palette defined
               
     20     - start of palette bytes, or pixel bytes if no palette defined */

$pixel_header[10] = WIDTH >> 8;
$pixel_header[11] = WIDTH & 255;
$pixel_header[12] = HEIGHT >> 8;
$pixel_header[13] = HEIGHT & 255;
$pixel_header[14] = BIT_DEPTH;
$pixel_header[15] = 0;
$pixel_header[16] = 0;
$pixel_header[17] = (@$PHC_control_code) ? 1 | BITS_REPEATS << 1 | BITS_OFFSET << 4 : 0;
$pixel_offset = (BIT_DEPTH == 16) ? 20 : 20 + ($img_colour_count * 2);
$pixel_header[18] = $pixel_offset >> 8;
$pixel_header[19] = $pixel_offset & 255;

$t = str_pad('PHC1.0', 10, chr(0));                         //PHC file identifier
foreach ($pixel_header as $v) $t .= chr($v);                //convert each value to its char code

$t .= @$pal_string;                                         //add palette code, if any

$a = str_split($pixel_bit_stream, 8);                       //split pixel bit stream into groups of 8 "bits"
$i = 0;
foreach ($a as $v) $t .= chr(bindec($v));                   //and convert each 8 characters to an actual byte value

$f = fopen(substr($fname, 0, -4).'.PHC', 'w');              //create PHC file, open for writing
fwrite($f, $t);                                             //write data stream
fclose($f);                                                 //and close file to save it



/* Reduce $pixel_bit_stream to two-byte chunks and convert to hex, before generating final C code. If the final bit
depth is less than 16, then a palette has been defined, which we'll include. If not, we omit it; each pixel will have
its RGB565 value defined instead. */ 

$pixel_data_chunks = str_split($pixel_bit_stream, 16);      //split bitstream into 4-hex code (2-byte) chunks
ob_start();                                                 //again, use the output buffer to capture the new hex code
echo sprintf('0x%04x', bindec($pixel_data_chunks[0]));      //convert first chunk to decimal, then hex using sprintf()
$loop_count = $pixel_byte_count / 2;                        //determine how often to loop through the pixel bit stream
for ($i = 1; $i < $loop_count; $i++) echo sprintf(', 0x%04x', bindec($pixel_data_chunks[$i]));      //and do it
$ob = ob_get_clean();                                       //save output buffer to variable $ob
unset($pixel_data_chunks);                                  //remove $pixel_data_chunks from memory once used

$code = '<p>C code:     
<p><pre style="background-color:#ffff99;white-space:pre-wrap" id="bitmap_code">'.$code.'const uint16_t '.$codefname.'_Bytes[] PROGMEM = {
   '.$ob." };
                 
const RGB565_BMP {$codefname} PROGMEM = {\n". 
(BIT_DEPTH < 16 ? "   (uint16_t *){$codefname}_Palette,\n" : '').
"   (uint16_t *){$codefname}_Bytes,
   ".WIDTH.", ".HEIGHT.",        //width, height
   ".BIT_DEPTH.",        //bit depth
   ".@$PHC_control_code."        //compression codes
};
  
//total $total_byte_count bytes</pre>";
unset($ob);



/* Display PROGMEM C code as specified by the user, if $show_code is set. Render in a hidden DIV if not, so the code is
accessible to the Javascript download function. Report size reduction achieved. */

if ($show_code) echo $code;   
else echo '<div style="display:none">'.$code.'</div>';

echo '<p>Size reduction from raw image data: '.round(($fsize - $total_byte_count) / $fsize * 100, 1).'%';



/* If desired, decode the compressed code to test it, mimicking how we decode it on a microprocessor like the Arduino
or the ESP8266. If not, display the image using its RGB565 colour definitions. In either case, we display the image in
its RGB565 form at the end of script execution. */

if ($test_decode) include 'decode RGB565.php';

else {
     unset($pixel_bit_stream);                              //if we're not decoding the pixel bit stream, remove it
                                                            //from memory
     $x = $y = 0;                                           //initialize x and y
     $im = imagecreatetruecolor(WIDTH, HEIGHT);             //our palette may go beyond 8 bits, which GD can't handle,
                                                            //so output true colour image even if decoding a palette
     if (@$rgb) {                                           //if a palette is defined,
          foreach ($pixel_bytes as $v) {                    //for each pixel index
               $v = $rgb[$v];                               //retrieve palette colour
               imagesetpixel($im, $x++, $y, imagecolorallocate($im, $v >> 11 << 3, (($v >> 5) & 0x3f) << 2, ($v & 0x1f) << 3)); 
                                                            //write pixel and advance to the next pixel position                                  
               if ($x == WIDTH) {                           //if we've reached the last pixel position in the row,
                    $x = 0;                                 //go to the first position in the next
                    $y++;
               }
          }
     }
     else {                                                 //if no palette is defined
          foreach ($pixel_bytes as $v) {                    //process each RGB565 value directly, in the same manner
               imagesetpixel($im, $x++, $y, imagecolorallocate($im, $v >> 11 << 3, (($v >> 5) & 0x3f) << 2, ($v & 0x1f) << 3));
               if ($x == WIDTH) {
                    $x = 0;
                    $y++;
               }
          }
     }

     echo '<p>Image using RGB565 colour values:';
     
     imagepng($im, 'generated_RGB565_bitmap.png');          //show image
     imagedestroy($im);                                     //remove image asset from memory
     echo '<p><img src="generated_RGB565_bitmap.png" style="margin-left:70px;width:'.WIDTH.'px;height:'.HEIGHT.'px">';
}



/* Download code for PROGMEM version. */

echo '<script>function download(filename) {
     x = document.getElementById("bitmap_code").innerText;
     e = document.createElement("a");
     e.setAttribute("href", "data:text/plain;charset=utf-8," + encodeURIComponent(x));
     e.setAttribute("download", filename);

     e.style.display = "none";
     document.body.appendChild(e);

     e.click();
     document.body.removeChild(e);
}
</script>

<p><form onsubmit="download(\''.$codefname.'.h\')">
  <input type="submit" value="Download Code">
</form>';

?>