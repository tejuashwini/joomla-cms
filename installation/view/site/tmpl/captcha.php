<?php

/**
 * This function will creates a session or resumes the current one based on a session identifier passed
 */
session_start();


/**
 * Method to validate the form data.
 *
 * @param   string $string The string
 * @param   bool $binary [optional]
 *
 */
$random_num    = md5(random_bytes(64));


/**
 * Generate captcha code and storing this captcha in captcha_code variable & file
 * *
 * @param   string $string The string
 * @param   int $offset
 *
 */
$captcha_code  = substr($random_num, 0, 6);
file_put_contents('filename.txt', $captcha_code);


/**
 * Assigning the captcha in the session
 * *
 */
$_SESSION['CAPTCHA_CODE'] = $captcha_code;


/**
 * Creating and allocating colors to the captcha image code
 * @param   int $width Image width.
 * @param   int $height Image height.
 * @return  $layer an image resource identifier on success, false on errors.
 * *
 */
$layer = imagecreatetruecolor(168, 37);
$captcha_bg = imagecolorallocate($layer, 247, 174, 71);


/**
 * Filling and allocating colors to the background of captcha code
 * @param
 * @param
 * *
 */
imagefill($layer, 0, 0, $captcha_bg);
$captcha_text_color = imagecolorallocate($layer, 0, 0, 0);


/**
 * Create captcha image string with captcha code along with color

 * @param   int $font
 * @param   int $x x-coordinate of the upper left corner.
 * @param   int $y y-coordinate of the upper left corner.
 * @param   string $string The string to be written.
 * @param   int $color A color identifier created with image color allocate.
 * @return  true on success or false on failure.
 * *
 */
imagestring($layer, 5, 55, 10, $captcha_code, $captcha_text_color);


/**
 * Passing header with content-type
 * *
 */
header("Content-type: image/jpeg");


/**
 * Generated captcha code in image format
 * @param
 * @param
 * @return  true on success or false on failure for the getting the image of captcha code
 * *
 */
imagejpeg($layer);
