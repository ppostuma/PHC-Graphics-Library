/**********************************************************************************************************************

PHC Graphics Functions, version 1.0
Graphics functions for displaying compressed, RGB565-formatted PHC files, from SPIFFS flash memory on the ESP8266.

Copyright (c) 2017, Paul Postuma

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated 
documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit
persons to whom the Software is furnished to do so.

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
THERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Do what you want with the code. Use, modify, redistribute however you choose - as long as I won't be held responsible.


USAGE:

Bodmer's excellent TFT_eSPI library must be installed, and FS.h included, i.e.

#include <TFT_eSPI.h>
#include <FS.h>

In its default configuration, it expects that the TFT screen is initialized as:

TFT_eSPI tft = TFT_eSPI();

and a file pointer of

fs::File fileSPI; 


CREATING THE RGB565 FILE:

A PHP script currently handles image conversion, so yes, you'll need to be able to run these. This is not ideal for
everyone, but it's platform-independent. It's well-annotated, so it should be easy to port to other languages.

The script will handle GIF, PNG, and BMP image files of every bit depth I've tested; it allows the user to select a
background colour for transparent images. More at https://github.com/ppostuma/PHC-Graphics-Library.


ON PHC COMPRESSION:

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


SEE ALSO:

Bodmer's TFT_eSPI library at https://github.com/Bodmer/TFT_eSPI
Peter Andersson's SPIFFS version 0.3.7 at https://github.com/pellepl/spiffs
Code and documentation at https://github.com/ppostuma/PHC-Graphics-Library

**********************************************************************************************************************/


#define PHC_BUFFER_SIZE 512                             //SPIFFS file read size; balances memory use and efficiency
#define PIXEL_BUFFER_SIZE 128                           //size for pixel writes using Bodmer's pushColor() function


// PHC variables, initialization

bool show_header_info = 0;                              //for debugging: shows basic file info over Serial

uint8_t  _PHC_buffer[PHC_BUFFER_SIZE];                  //buffer for file read from SPIFFS
uint16_t _ptr;                                          //pointer to current byte in buffer
uint8_t _curr_byte;                                     //value of code byte currently being read
uint8_t _bit_index;                                     //index of bit being read, starting with highest/MSB
bool _code_value;                                       //value of single code bit

uint8_t _pixel_buffer[PIXEL_BUFFER_SIZE];               //buffer for pixel bytes
uint8_t _pixel_buffer_index;                            //pointer to pixel position in buffer
uint8_t _pixel_highByte;
uint8_t _pixel_lowByte;


// Function showPHC - display the file at the specified location

bool showPHC(const char* _filename, uint16_t _x_origin, uint16_t _y_origin) {
  uint32_t _i;
  uint16_t _width;
  uint16_t _height;
  
  uint8_t _bit_depth;                                   //bit depth used to encode palette entries
  uint8_t _bits_repeats;                                //bits used to encode length of repeat segments
  uint8_t _bits_offset;                                 //bits used for runs of pixels that are copied as is
  uint16_t _repeats = 0;                                //number of times a pixel value is to be repeated
  uint16_t _offset;                                     //offset position at which coding (non-pixel) bits resume
  
  bool _PHC_control_code;                               //identifies if compression is used
  
  uint16_t _pixel_offset;                               //start location for pixel data
  uint32_t _pixel_count;                                //number of pixels in image
  uint32_t _pixels_processed = 0;                       //number of pixels processed
  uint16_t _pixel_code;                                 //pixel code or palette index value
  
  uint16_t _palette_byte_count;                         //number of bytes in palette

  _bit_index = 7;                                       //start reading information with the MSB
  _pixel_buffer_index = 0;                              //location of pixel information in buffer


// Open and begin reading file header. Process palette if one exists
      
  fileSPI = SPIFFS.open(_filename, "r");                //get file handle
  fileSPI.read(_PHC_buffer, PHC_BUFFER_SIZE);           //read first part of file into buffer
  
  if (_PHC_buffer[0] == 'P' && _PHC_buffer[1] == 'H' && _PHC_buffer[2] == 'C') {    //confirm PHC file type
    _width = _PHC_buffer[10] << 8 | _PHC_buffer[11];                                //read width, height from buffer
    _height = _PHC_buffer[12] << 8 | _PHC_buffer[13];
    _pixel_count = _width * _height;                                                //calculate pixel count
      
    _bit_depth = _PHC_buffer[14];                       //read number of bits used to encode palette

    _i = _PHC_buffer[17];                               //compression parameters
    _PHC_control_code = _i & 1;                         //read bit 0
    if (_PHC_control_code) {                            //if set, file has been compressed
      _bits_repeats = _i & 15;                          //bits 1-3 encode the number of repeats
      _bits_repeats >>= 1;
      _bits_offset = _i >> 4;                           //bits 4-7 encode the offset
    }

    _ptr = _PHC_buffer[18] << 8 | _PHC_buffer[19];      //start of pixel data in buffer
    _palette_byte_count = _ptr - 20;                    //number of bytes used by palette
    uint8_t _palette[_palette_byte_count];              //initialize palette if one exists

    if (_bit_depth != 16) {                             //for paletted images
      _i = PHC_BUFFER_SIZE - 20;                        //max number of palette bytes in initial read to buffer
      if (_palette_byte_count > _i) {                   //if actual number of palette bytes is larger
        memcpy(_palette, _PHC_buffer + 20, _i);         //copy buffered palette bytes to the palette
        fileSPI.read(_PHC_buffer, PHC_BUFFER_SIZE);     //read the next data chunk from SPIFFS
        memcpy(_palette + _i, _PHC_buffer, _palette_byte_count - _i);   //copy remaining palette bytes into the palette
        _ptr -= PHC_BUFFER_SIZE;
      }
      else memcpy(_palette, _PHC_buffer + 20, _palette_byte_count);     //copy palette bytes to palette
    }
    
    if (show_header_info) {                             //mostly for debugging: print header info to serial output
      Serial.print(fileSPI.name());
      Serial.printf(", Length: %u\n", fileSPI.size());
      Serial.println("Valid PHC file:");
      Serial.printf("%ux%u; %ubit palette, %u colours; compression %u/%u\n", _width, _height, _bit_depth,
                    _palette_byte_count / 2, _bits_repeats, _bits_offset);
    }
  

// Read actual image data, decompress as required, and write to screen

    tft.setAddrWindow(_x_origin, _y_origin, _x_origin + _width - 1, _y_origin + _height - 1);  
                                                        //initialize TFT target area

    // Compressed images
                                                        
    if (_PHC_control_code) {                            //check for file compression
      readPHC_bit();                                    //read first data bit, a control bit

      // Paletted images
  
      if (_bit_depth != 16) while (_pixels_processed < _pixel_count) {
        while (_code_value == 1) {                      //for control code 1
          _offset = readPHC_nbits(_bits_offset);        //read _bits_offset number of bits for the offset as encoded
          _offset++;                                    //and increment by one for the actual offset
          _pixels_processed += _offset;

          for (_i = 0; _i < _offset; _i++) {
            _pixel_code = readPHC_nbits(_bit_depth);    //get the pixel code, then the pixel value from the palette,
                                                        //and write high and low bytes to the pixel buffer 
            _pixel_highByte = _pixel_buffer[_pixel_buffer_index++] = _palette[ _pixel_code * 2];
            _pixel_lowByte = _pixel_buffer[_pixel_buffer_index++] = _palette[( _pixel_code * 2) + 1];
        
            if (_pixel_buffer_index == PIXEL_BUFFER_SIZE) {       //when the buffer is full,
              tft.pushColors(_pixel_buffer, PIXEL_BUFFER_SIZE);   //write pixels to screen
              _pixel_buffer_index = 0;                            //and reset the pointer to the start of the buffer
            }
          }
  
          readPHC_bit();                                //read the next control bit
        }
  
        _repeats = 0;
        while (_code_value == 0 && (_pixels_processed + _repeats) < _pixel_count) {     //for control code 0
           if (_bits_repeats) _repeats += readPHC_nbits(_bits_repeats);                 //read number of bits used for
                                                                                        //repeats
           _repeats++;                                  //increment by one for the actual number of repeats  
           readPHC_bit();                               //read the next control bit
        }
        if (_repeats) {
          _pixels_processed += _repeats;                //increment pixels processed
          repeatPixel(_repeats);                        //and write them to the pixel buffer
        }
      }
  
  
      // 16-bit images - no palette decoding required
    
      else while (_pixels_processed < _pixel_count) {
        while (_code_value == 1) {                      //for control code 1
          _offset = readPHC_nbits(_bits_offset);        //read the bits used to encode the offset
          _offset++;                                    //and increment by one for the actual offset
          _pixels_processed += _offset;
  
          for (_i = 0; _i < _offset; _i++) {            //for each pixel, read 8 bits for each of the high and low
                                                        //bytes, and write to the pixel buffer
            _pixel_highByte = _pixel_buffer[_pixel_buffer_index++] = readPHC_nbits(8);  
            _pixel_lowByte = _pixel_buffer[_pixel_buffer_index++] = readPHC_nbits(8);
          
            if (_pixel_buffer_index == PIXEL_BUFFER_SIZE) {       //when the buffer is full
              tft.pushColors(_pixel_buffer, PIXEL_BUFFER_SIZE);   //write pixels to screen
              _pixel_buffer_index = 0;                            //reset the pointer to the start of the buffer
            }
          }
  
          readPHC_bit();                                //read the next control bit
        }
  
        _repeats = 0;
        while (_code_value == 0 && (_pixels_processed + _repeats) < _pixel_count) {     //process repeats
           if (_bits_repeats) _repeats += readPHC_nbits(_bits_repeats);                 //exactly as before
           _repeats++;
           readPHC_bit();      //and read the next control bit
        }
        if (_repeats) {
          _pixels_processed += _repeats;
          repeatPixel(_repeats);
        }
      }
    }


    // Uncompressed image files: read palette index values or colour values directly, and push to screen
  
    else {
      if (_bit_depth != 16) while (_pixels_processed++ < _pixel_count) {          //for paletted images
        _pixel_code = readPHC_nbits(_bit_depth);                                  //read the pixel code 
    
        _pixel_buffer[_pixel_buffer_index++] = _palette[_pixel_code * 2];         //get its high byte from palette
        _pixel_buffer[_pixel_buffer_index++] = _palette[(_pixel_code * 2) + 1];   //get the low byte, write to buffer
    
        if (_pixel_buffer_index == PIXEL_BUFFER_SIZE) {                 //push pixels when the buffer is full
          tft.pushColors(_pixel_buffer, PIXEL_BUFFER_SIZE);
          _pixel_buffer_index = 0;
        }
      }
      
      else while (_pixels_processed++ < _pixel_count) {                 //paletted images
        _pixel_buffer[_pixel_buffer_index++] = readPHC_nbits(8);        //read high byte and write to buffer
        _pixel_buffer[_pixel_buffer_index++] = readPHC_nbits(8);        //same for low byte

        if (_pixel_buffer_index == PIXEL_BUFFER_SIZE) {                 //etc., as before
          tft.pushColors(_pixel_buffer, PIXEL_BUFFER_SIZE);
          _pixel_buffer_index = 0;
        }
      }
    }
    
    //when all image data has been decoded, write any unwritten pixels to screen by flushing the pixel buffer
    
    if (_pixel_buffer_index) tft.pushColors(_pixel_buffer, _pixel_buffer_index);
    
    fileSPI.close();                                    //close file
    return 1;
  }
  
  else {
    Serial.println("No valid PHC file found");          //no file found - report via Serial
    fileSPI.close();                                    //release file handle
    return 0;                                           //if no valid PHC file, return 0
  }
}


// Function repeatPixel - writes previous pixel's high and low bytes to pixel buffer n times, and pushes to screen if
// buffer is full

void repeatPixel(uint16_t n) {
  for (n; n > 0; n--) {
    _pixel_buffer[_pixel_buffer_index++] = _pixel_highByte;  
    _pixel_buffer[_pixel_buffer_index++] = _pixel_lowByte;

    if (_pixel_buffer_index == PIXEL_BUFFER_SIZE) {                //and if the buffer is full, write pixels, reset index
      tft.pushColors(_pixel_buffer, PIXEL_BUFFER_SIZE);
      _pixel_buffer_index = 0;
    }
  }
}


// Function readPHC_bit - reads and returns single bit from the current byte in the PHC buffer, and advances bit
// pointer. Reading starts at bit 7, the Most Significant Bit or MSB. If we're reading bit 7, we're reading a new byte:
// advance to this byte and read it; read the next series of bytes into the buffer if required

bool readPHC_bit() {
  if (_bit_index == 7) {                                //if bit 7 or the byte's MSB is to be read
    if (_ptr == PHC_BUFFER_SIZE - 1) {                  //if pointing to the last byte in the buffer
      _curr_byte = _PHC_buffer[_ptr];                   //read this byte
      _ptr = 0;                                         //reset the pointer to the first byte of the buffer
      fileSPI.read(_PHC_buffer, PHC_BUFFER_SIZE);       //read the next series of bytes into the buffer
    }
    else _curr_byte = _PHC_buffer[_ptr++];              //else just read the new byte and advance the pointer
  }
  _code_value = (_curr_byte >> _bit_index) & 1;         //all bits: shift 'em and AND to read only the bit of interest
  if (_bit_index) _bit_index--;                         //if _bit_index is non-zero, decrement by one
  else _bit_index = 7;                                  //if zero, reset to 7
  return _code_value;                                   //return the value of the bit being read
}


// Function readPHC_nbits reads n bits using readPHC_bit and returns their value

uint8_t readPHC_nbits(uint8_t n) {
  uint8_t _nbit_value = 0;
  while (n) _nbit_value |= readPHC_bit() << --n;        //for each bit, left shift previous result; add new bit value
  return _nbit_value;
}
