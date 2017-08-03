# PHC Graphics Library, version 1.0

This is a graphics library for a new image format, PHC. Images files are lossless RGB565-encoded, either paletted or full-colour, with optional custom RLE-compression, and are created from BMP, GIF, or PNG. Transparency is removed but a background colour may be specified during conversion. Images are read from SPIFFS and rendered to TFT display screens using [Bodmer's excellent TFT_eSPI library](https://github.com/Bodmer/TFT_eSPI), typically about twice as fast as JPGs rendered using his (also excellent) [JPEGDecoder](https://github.com/Bodmer/JPEGDecoder).

An annotated PHP script is included to produce these files. 

SPIFFS version 0.3.7 or better is recommended for fastest SPIFFS file reads.


## Background:

Existing image display libraries are often slow. Even Bodmer's (a drop-in replacement for the Adafruit GFX libraries), with his JPEG Decoder, takes on average a full second to write a 320x240 image to screen with an ESP8266 running at 80 MHz, though with the MCU at 160 MHz, flash at 80 MHz, and a tightly-compressed JPG speeds of under 400 milliseconds are sometimes possible. And JPGs are lossy, though this rarely an issue on a low-res TFT screen.

Bitmaps require further workarounds. The Adafruit library, and Bodmer's, do display them, but require an external SD card and card reader. These transfer data plenty fast (faster than reading the same image from the ESP8266's flash memory!), but create new problems. Code is required to instance the SD card and additional pins are used for communications. Also, SD card readers draw a significant amount of power on boot, enough that it can prevent proper initialization when powered up simultaneously with some ESP8266. SD card readers take up room, and SD cards can dislodge if shaking is a possibility.

Bitmaps also take longer to process than compressed images; each pixel must be converted from 8-, 24- or 32-bit format to RGB565.

PHC addresses these issues. Information is stored in lossless RGB565 form, with optional custom RLE-type compression. To further reduce image size, palette indices use only the minimum number of bits required. 

Images are stored in SPIFFS. (I did write the C code to store this in PROGMEM area but never developed the decompression/display code because of PROGMEM's size constraints - feel free to write this if you wish.) 

Here, the actual decompressed images, at the bit depth indicated. Speed is good compared to that for the equivalent JPG:

Image | Test image size | Number of colours | File size (bytes) | Display speed at 80 MHz (msec) | JPEGDecoder at 80 MHz (msec)
--- | :---: | :---: | :---: | :---: | :---:
![alt text](https://github.com/ppostuma/PHC-Graphics-Library/blob/master/PHC%20decompressed%20bike.png "decompressed PHC image")  bike.PHC | 29x22 | 2 | 94 | 1.84 | 8
![alt text](https://github.com/ppostuma/PHC-Graphics-Library/blob/master/PHC%20decompressed%20death%20star.png "decompressed PHC image")  deathstar_25pal.PHC | 204x204 | 25 | 13,833 | 161 | 339
![alt text](https://github.com/ppostuma/PHC-Graphics-Library/blob/master/PHC%20decompressed%20parrots.png "decompressed PHC image")  parrots256.PHC | 240x200 | 256 | 96,020 | 264 | 438
![alt text](https://github.com/ppostuma/PHC-Graphics-Library/blob/master/PHC%20decompressed%20trainer.png "decompressed PHC image")  trainer32_wu.PHC | 320x240 | 32 | 29,912 | 368 | 703

And, obviously, speed is even faster at 160 MHz - my 320x240 test image renders in 202 milliseconds.

Downsides - large, full-colour PHC files may even be slower than JPG, and don't compress down near as much.


## Dependencies

[Bodmer's TFT_eSPI library](https://github.com/Bodmer/TFT_eSPI)

Highly recommended: Peter Andersson's SPIFFS version 0.3.7 or better. Depending on where in SPIFFS a file is located, it can read extremely slowly, an idiosyncracy that appears to have been resolved with 0.3.7. For example, one particular file took 845 milliseconds to read with version 0.3.6 and 917 with version 0.3.4; with version 0.3.7 it takes 52. Original code at https://github.com/pellepl/spiffs; my fork at https://github.com/ppostuma/spiffs


## Pre-processing

Pre-processing to produce images with an as-small-as-possible palette is highly recommended. Aim for a palette of 256, 128, 64, 32, 16, 8, 4 or 2 colours; intermediate values lower quality without improving speed or file size. Even reducing images down to a palette of 32 colours can produce very reasonable images on a TFT screen - see the trainer32_wu.PHC image above. 

Recommended options for pre-processing:

* IrfanView - reduce colour depth, with "Use best color quality" is good; almost on par with Wu and Neuquant. With "best color" and Floyd-Steinberg dithering is very good, but shows some visible dithering
* IrfanView, with RIOT plugin - click on "Save for Web", select GIF, then "Color reduction." Drag the slider to the number of colours you wish, and select either the Xiaolin Wu or NeuQuant quantizers, then save. Good
* RIOT standalone at http://luci.criosweb.ro/riot/ - Radical Image Optimization Tool - Works as above, again, save as GIF. Good quality. Beware that the installer will also install other uninvited software by default 
* Ximagic Quantizer - shareware. NeuQuant, Xiaolin Wu v2, and Dennis Lee v3 all have very good colour rendition. Using a small amount of dithering can also be useful

## Converting to PHC:

I've written a PHP script to handle the image conversion. This won't be ideal for everyone, but it's platform-independent, and it allowed me to re-use code from another project. I have made an effort to annotate it pretty heavily, so it should be easy to port to other languages for anyone who wishes to.

Some features:

* takes GIF, PNG, and BMP files as input
* handles input files of every bit depth I've tested, including formats I've not found in the wild, like 3-bit images
* handles transparency properly, including blended transparency/full alpha-channel; many file converters don't!!
* for images with transparency, can use a colour-picker to choose the desired background colour
* optionally shows the output image using a PHP decoder script that is structured virtually identically to the Arduino function
* generates PHC file with the same name in script directory
     
Limitations:

* file sizes can get large for full-colour, larger images
* encoding large images is slow - can take seconds for images of 320x240 pixels or more, as the script determines the optimal compression strategy (this is where a small stand-alone program would be much better, but I simply can't be bothered as the script as is does what I need it to, very nicely)
* PHP scripts can choke and crash on images that are too large
* decoding is slower for full-colour, larger images

## Finally: USAGE

You have:

* installed Bodmer's library and configured it for your ESP8266 and TFT screen
* optionally-but-recommended: updated SPIFFS to 0.3.7
* optimized your images, and converted to PHC

To run the demo sketch:

* download the files. Load PHC_graphics_demo.INO. This should pull PHC_graphics into a second tab.
* select Tools > ESP8266 Sketch Data Upload to write the four PHC files to SPIFFS
* cycle power to the ESP8266, then upload the sketch

Good luck!
     
I hope this is useful to someone else. Feel free to adapt and use to your heart's content ..
