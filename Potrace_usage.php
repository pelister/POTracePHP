<?php
/* Potrace 1.16, written by Peter Selinger (2001-2019). (https://potrace.sourceforge.net) 
 * PHP Port by Savoul Pelister (2025). (https://techlister.com/blog)
 * A PHP Port of Potrace,
 * 
 *  
 * Licensed under the GPL
 *
 */
 
include('Potrace.php');

$pot = new POtracePHP();

// -a alphamax from 0.0 to 1.33 (> 1.33 will make no difference )
// -O opttolerance defauult 0.2 ( set the curve optimization tolerance. The default value is 0.2. Larger values allow more consecutive Bezier curve segments to be joined together in a single segment, at the expense of accuracy. ) 

$pot->setParameter([
	'turnpolicy' => "minority",
	'alphamax' => 1, ///default 1, range 0.0 to 1.33
	'opttolerance' => 0.2, //default 0.2, range 0.0 to 4.0
	'flat' => true,
	//'group' => true,
	'turdsize' => 0, //default 2
	'coord' => 'relative', //relative or absolute
	//'stroke' => true, // false will fill the close paths with 'fillcolor'
	//'width' => 400,
	//'height' => 400 , //width and height of SVG
	//'margins' => array('left' => 50, 'right' => 50, 'top' => 50, 'bottom' => 50),
	'bg' => true, //adds a fillable box <rect> as BG.
	'unit' => 10, //default 10 (Potrace default is 10)
	'fillcolor' => '#ffffff', //fill color
	'linecolor' => '#000000' //sets line color.
]); 
 
$pot->loadImageFromFile('hand.jpg', 128);
 
$pot->process();

$svg = $pot->getSVG(1);
$pdf = $pot->getPDF(1);
$eps = $pot->getEPS(1);


file_put_contents('output.svg', $svg);
file_put_contents('output.pdf', $pdf);
file_put_contents('output.eps', $eps);