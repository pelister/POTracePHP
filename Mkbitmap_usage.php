<?php
/* Mkbitmap, written by Peter Selinger (2001-2019). (https://potrace.sourceforge.net) 
 * PHP Port by Savoul Pelister (2025). (https://techlister.com/blog)
 * A PHP Port of Potrace,
 * 
 *  
 * Licensed under the GPL
 */
 
include('Mkbitmap.php');
// Usage
$mkbitmap = new MkBitmap(__DIR__ . '/' . 'cartoon-trex.jpg');
$mkbitmap->process([
    'scale'     => 1.0,     // mkbitmap default is 2x
    'filter'    => 4,
    'threshold' => 0.45,
    'blur'      => 0,
    'invert'    => false,
	//'colorize'       => __DIR__ . '/' . 'rabbit-vector-clipart.jpg',    // ← NEW: path to color image or false
	//'colorize_strength' => 1.0,
	//'transparent' => true // false
]);
$mkbitmap->save(__DIR__ . '/'. 'mkbitmap.png');