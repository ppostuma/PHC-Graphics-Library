const char *version = "graphics PHC, v1.0";       //just habit: Serial print the name of the code I'm working on
uint16_t delayInterval = 3000;                    //timing delay between code loops, in milliseconds

#include <TFT_eSPI.h>                             //graphics: replaces the Adafruit GFX and TFT libraries
#include <FS.h>                                   //read files from SPIFFS

TFT_eSPI tft = TFT_eSPI();                        //initialize TFT screen; PHC library expects it to be called "tft"
fs::File fileSPI;                                 //file handle



void setup() {
  Serial.begin(115200);                           //start Serial communications
  Serial.println(version);                        //print code and version information

  tft.begin();                                    //initialize TFT screen
  tft.setRotation(1);                             //landscape mode
  tft.fillScreen(ILI9341_BLACK);                  //set background to black

  if (!SPIFFS.begin()) Serial.println("Failed to initialize SPIFFS");
}


// MAIN LOOP: show images

void loop() {
  showPHC("/bike.PHC", 100, 40);                  //write image from SPIFFS to screen
  delay(delayInterval);                           //for the predetermined time
  tft.fillScreen(ILI9341_BLACK);                  //blank out screen

  showPHC("/deathstar_25pal.PHC", 50, 20);        //and repeat
  delay(delayInterval);
  tft.fillScreen(ILI9341_BLACK);

  showPHC("/parrots256.PHC", 40, 20);
  delay(delayInterval);
  tft.fillScreen(ILI9341_BLACK);

  showPHC("/trainer32_wu.PHC", 0, 0);
  delay(delayInterval);
  tft.fillScreen(ILI9341_BLACK);
}
