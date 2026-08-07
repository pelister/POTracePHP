<?php
/* Potrace 1.16, written by Peter Selinger (2001-2019). (https://potrace.sourceforge.net) 
 * PHP Port by Savoul Pelister (2025). (https://techlister.com/blog)
 * A PHP Port of Potrace,
 * 
 *  
 * Licensed under the GPL
 * 
 * 
 */

class Point{
  public $x;
  public $y;
  
  public function __construct($x=NULL, $y=NULL) {
    if($x !== NULL)
      $this->x = $x;
    if($y !== NULL)
      $this->y = $y;
  }
}

class Opti{
  public $pen = 0;
  public $c;
  public $t = 0;
  public $s = 0;
  public $alpha = 0;
  
  public function __construct(){
    $this->c = array(new Point(), new Point());
  }
}

class Bitmap{
  public $w;
  public $h;
  public $size;
  public $data;

  public function __construct($w, $h){
    $this->w = $w;
    $this->h = $h;
    $this->size = $w * $h;
    $this->data = array();
  }

  public function at($x, $y) {
    return ($x >= 0 && $x < $this->w && $y >=0 && $y < $this->h) && 
          $this->data[$this->w * $y + $x] === 1;
  }

  public function index($i) {
    $point = new Point();
    $point->y = floor($i / $this->w);
    $point->x = $i - $point->y * $this->w;
    return $point;
  }

  public function flip($x, $y) {
    if ($this->at($x, $y)) {
      $this->data[$this->w * $y + $x] = 0;
    } else {
      $this->data[$this->w * $y + $x] = 1;
    }
  }
}

class Path{
  public $area = 0;
  public $len = 0;
  public $curve = array();
  public $pt = array();
  public $minX = 100000;
  public $minY = 100000;
  public $maxX= -1;
  public $maxY = -1;
  public $sum = array();
  public $lon = array();
}

class Curve{
  public $n;
  public $tag;
  public $c;
  public $alphaCurve = 0;
  public $vertex;
  public $alpha;
  public $alpha0;
  public $beta;

  public function __construct($n){
    $this->n = $n;
    $this->tag = array_fill(0, $n, NULL);
    $this->c = array_fill(0, $n * 3, NULL);
    $this->vertex = array_fill(0, $n, NULL);
    $this->alpha = array_fill(0, $n, NULL);
    $this->alpha0 = array_fill(0, $n, NULL);
    $this->beta = array_fill(0, $n, NULL);
  }
}

class Quad{
  public $data = array(0,0,0,0,0,0,0,0,0);

  public function at($x, $y) {
    return $this->data[$x * 3 + $y];
  }
}

class Sum{
  public $x;
  public $y;
  public $xy;
  public $x2;
  public $y2;

  public function __construct($x, $y, $xy, $x2, $y2) {
    $this->x = $x;
    $this->y = $y;
    $this->xy = $xy;
    $this->x2 = $x2;
    $this->y2 = $y2;
  }
}

class POtracePHP{
  public $imgElement;
  public $imgCanvas;
  public $bm = NULL;
  public $pathlist = array();
  public $info = array('turnpolicy' => "minority", 
                       'turdsize' => 2,
                       'optcurve' => TRUE,
                       'alphamax' => 1,
                       'opttolerance' => 0.2,
					   'flat' => false, //  true for separate subpaths
                       'group' => false, // true for <g> grouping
                       'stroke' => false, // true for line drawing, false for filled
					   'coord' => "absolute",
					   'unit' => 10,
					   'margins' => array('left' => 0, 'right' => 0, 'top' => 0, 'bottom' => 0),
					   'width' => null,
                       'height' => null,
                       'crop' => false,
					   'bg' => false,
					   'fillcolor' => '#ffffff',
					   'linecolor' => '#000000',
				);

  public function __construct($data=array()){
    $this->setParameter($data);
  }
  
	public function setParameter($data){
	  // Defaults
	  $defaults = array(
		'flat' => true,   // default matches current behaviour
		'group' => false,  // off unless explicitly set
		'margins' => array('left' => 0, 'right' => 0, 'top' => 0, 'bottom' => 0),
        'width' => null,
        'height' => null,
        'crop' => false,
		'unit' => 10
	  );

	  // Merge with existing info and user parameters
	  $this->info = (object) array_merge($defaults, (array) $this->info, (array) $data);
	  // If both are true, flat takes precedence (like potrace)
	  if (!empty($this->info->flat) && !empty($this->info->group)) {
		$this->info->group = false;
	  }
	}


  public function loadImageFromFile($file, $thresh = 128){
    list($w, $h) = getimagesize($file);
    $image = imagecreatefromstring(file_get_contents($file));
	
	$isIndexed = imageistruecolor($image) ? false : true;
    
    $this->bm = new Bitmap($w, $h);
	
	if ($isIndexed) {
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$index = imagecolorat($image, $x, $y);
				
				$colors = imagecolorsforindex( $image, $index );
				$gray = ($colors['red'] + $colors['green'] + $colors['blue'])/3;
				$this->bm->data[] = ($gray < $thresh) ? 1 : 0;
			}
		}
	}
	else{
		
		for($i=0; $i<$h; $i++){
		  for($j=0; $j<$w; $j++){
			$rgb = imagecolorat($image, $j, $i);
			$r = ($rgb >> 16) & 0xFF;
			$g = ($rgb >> 8) & 0xFF;
			$b = $rgb & 0xFF;
			$color = (0.2126 * $r) + (0.7153 * $g) + (0.0721 * $b);
			$this->bm->data[] = $color < $thresh ? 1 : 0;			
		  }
		}
	}
  }

  private function bmToPathlist(){
    $info = $this->info;
    $bm = &$this->bm;
    $bm1 = clone $bm;
    $currentPoint = new Point(0, 0);
    
    $findNext = function($point) use ($bm1) {
      $i = $bm1->w * $point->y + $point->x;
      while ($i < $bm1->size && $bm1->data[$i] !== 1) {
        $i++;
      }
      if($i < $bm1->size)
        return $bm1->index($i);
      return false;
    };
    
    $majority = function($x, $y) use ($bm1) {
      for ($i = 2; $i < 5; $i++) {
        $ct = 0;
        for ($a = -$i + 1; $a <= $i - 1; $a++) {
          $ct += $bm1->at($x + $a, $y + $i - 1) ? 1 : -1;
          $ct += $bm1->at($x + $i - 1, $y + $a - 1) ? 1 : -1;
          $ct += $bm1->at($x + $a - 1, $y - $i) ? 1 : -1;
          $ct += $bm1->at($x - $i, $y + $a) ? 1 : -1;
        }
        if ($ct > 0) {
          return 1;
        } else if ($ct < 0) {
          return 0;
        }
      }
      return 0;
    };
    
    $findPath = function($point) use($bm, $bm1, $majority, $info) {
      $path = new Path();
      $x = $point->x;
      $y = $point->y;
      $dirx = 0; $diry = 1;
      
      $path->sign = $bm->at($point->x, $point->y) ? "+" : "-";
      
      while (1) {
        $path->pt[] = new Point($x, $y);
        if ($x > $path->maxX)
          $path->maxX = $x;
        if ($x < $path->minX)
          $path->minX = $x;
        if ($y > $path->maxY)
          $path->maxY = $y;
        if ($y < $path->minY)
          $path->minY = $y;
        $path->len++;
        
        $x += $dirx;
        $y += $diry;
        $path->area -= $x * $diry;
        
        if ($x === $point->x && $y === $point->y)
          break;
        
        $l = $bm1->at($x + ($dirx + $diry - 1 ) / 2, $y + ($diry - $dirx - 1) / 2);
        $r = $bm1->at($x + ($dirx - $diry - 1) / 2, $y + ($diry + $dirx - 1) / 2);
        
        if ($r && !$l) {
          if ($info->turnpolicy === "right" ||
              ($info->turnpolicy === "black" && $path->sign === '+') ||
              ($info->turnpolicy === "white" && $path->sign === '-') ||
              ($info->turnpolicy === "majority" && $majority($x, $y)) ||
              ($info->turnpolicy === "minority" && !$majority($x, $y))) {
            $tmp = $dirx;
            $dirx = - $diry;
            $diry = $tmp;
          } else {
            $tmp = $dirx;
            $dirx = $diry;
            $diry = - $tmp;
          }
        } else if ($r) {
          $tmp = $dirx;
          $dirx = - $diry;
          $diry = $tmp;
        } else if (!$l) {
          $tmp = $dirx;
          $dirx = $diry;
          $diry = - $tmp;
        }
      }
      return $path;
    };
    
    $xorPath = function ($path) use(&$bm1){
      $y1 = $path->pt[0]->y;
      $len = $path->len;
      
      for ($i = 1; $i < $len; $i++) {
        $x = $path->pt[$i]->x;
        $y = $path->pt[$i]->y;
        
        if ($y !== $y1) {
          $minY = $y1 < $y ? $y1 : $y;
          $maxX = $path->maxX;
          for ($j = $x; $j < $maxX; $j++) {
            $bm1->flip($j, $minY);
          }
          $y1 = $y;
        }
      }
    };
    
    while ($currentPoint = $findNext($currentPoint)) {
      $path = $findPath($currentPoint);
      
      $xorPath($path);
      
      if ($path->area > $info->turdsize) {
        $this->pathlist[] = $path;
      }
    }
  }

  private function processPath() {
	 
    $info = $this->info;
    
    $mod = function ($a, $n) {
      return $a >= $n ? $a % $n : ($a>=0 ? $a : $n-1-(-1-$a) % $n);
    };
    
    $xprod = function ($p1, $p2) {
      return $p1->x * $p2->y - $p1->y * $p2->x;
    };
    
    $cyclic = function ($a, $b, $c) {
      if ($a <= $c) {
        return ($a <= $b && $b < $c);
      } else {
        return ($a <= $b || $b < $c);
      }
    };
    
    $sign = function ($i) {
      return $i > 0 ? 1 : ($i < 0 ? -1 : 0);
    };
    
    $quadform = function ($Q, $w) {
      $v = array_fill(0, 3, NULL);
      
      $v[0] = $w->x;
      $v[1] = $w->y;
      $v[2] = 1;
      $sum = 0.0;
      
      for ($i=0; $i<3; $i++) {
        for ($j=0; $j<3; $j++) {
          $sum += $v[$i] * $Q->at($i, $j) * $v[$j];
        }
      }
      return $sum;
    };
    
    $interval = function ($lambda, $a, $b) {
      $res = new Point();
      
      $res->x = $a->x + $lambda * ($b->x - $a->x);
      $res->y = $a->y + $lambda * ($b->y - $a->y);
      return $res;
    };
    
    $dorth_infty = function ($p0, $p2) use($sign) {
      $r = new Point();
      
      $r->y = $sign($p2->x - $p0->x);
      $r->x = - $sign($p2->y - $p0->y);
      
      return $r;
    };
    
    $ddenom = function ($p0, $p2) use ($dorth_infty){
      $r = $dorth_infty($p0, $p2);
      
      return $r->y * ($p2->x - $p0->x) - $r->x * ($p2->y - $p0->y);
    };
    
    $dpara = function ($p0, $p1, $p2) {
      $x1 = $p1->x - $p0->x;
      $y1 = $p1->y - $p0->y;
      $x2 = $p2->x - $p0->x;
      $y2 = $p2->y - $p0->y;
      
      return $x1 * $y2 - $x2 * $y1;
    };
    
    $cprod = function ($p0, $p1, $p2, $p3) {
      $x1 = $p1->x - $p0->x;
      $y1 = $p1->y - $p0->y;
      $x2 = $p3->x - $p2->x;
      $y2 = $p3->y - $p2->y;
      
      return $x1 * $y2 - $x2 * $y1;
    };
    
    $iprod = function ($p0, $p1, $p2) {
      $x1 = $p1->x - $p0->x;
      $y1 = $p1->y - $p0->y;
      $x2 = $p2->x - $p0->x;
      $y2 = $p2->y - $p0->y;
      
      return $x1 * $x2 + $y1 * $y2;
    };
    
    $iprod1 = function ($p0, $p1, $p2, $p3) {
      $x1 = $p1->x - $p0->x;
      $y1 = $p1->y - $p0->y;
      $x2 = $p3->x - $p2->x;
      $y2 = $p3->y - $p2->y;
      
      return $x1 * $x2 + $y1 * $y2;
    };
    
    $ddist = function ($p, $q) {
      return sqrt(($p->x - $q->x) * ($p->x - $q->x) + ($p->y - $q->y) * ($p->y - $q->y));
    };
    
    $bezier = function ($t, $p0, $p1, $p2, $p3) {
      $s = 1 - $t; $res = new Point();
      
      $res->x = $s * $s * $s * $p0->x
              + 3*($s * $s * $t) * $p1->x
              + 3*($t * $t * $s) * $p2->x
              + $t * $t * $t * $p3->x;
      
      $res->y = $s * $s * $s * $p0->y
              + 3*($s * $s * $t) * $p1->y
              + 3*($t * $t * $s) * $p2->y
              + $t * $t * $t * $p3->y;
      
      return $res;
    };
    
    $tangent = function ($p0, $p1, $p2, $p3, $q0, $q1) use($cprod){
      $A = $cprod($p0, $p1, $q0, $q1);
      $B = $cprod($p1, $p2, $q0, $q1);
      $C = $cprod($p2, $p3, $q0, $q1);
      $a = $A - 2 * $B + $C;
      $b = -2 * $A + 2 * $B;
      $c = $A;
      
      $d = $b * $b - 4 * $a * $c;
      
      if ($a==0 || $d<0) {
        return -1.0;
      }
      
      $s = sqrt($d);

      $r1 = (-$b + $s) / (2 * $a);
      $r2 = (-$b - $s) / (2 * $a);
      
      if ($r1 >= 0 && $r1 <= 1) {
        return $r1;
      } else if ($r2 >= 0 && $r2 <= 1) {
        return $r2;
      } else {
        return -1.0;
      }
    };
    
    $calcSums = function (&$path) {
      $path->x0 = $path->pt[0]->x;
      $path->y0 = $path->pt[0]->y;
      
      $path->sums = array();
      $s = &$path->sums;
      $s[] = new Sum(0, 0, 0, 0, 0);
      for($i = 0; $i < $path->len; $i++){
        $x = $path->pt[$i]->x - $path->x0;
        $y = $path->pt[$i]->y - $path->y0;
        $s[] = new Sum($s[$i]->x + $x, $s[$i]->y + $y, $s[$i]->xy + $x * $y,
                       $s[$i]->x2 + $x * $x, $s[$i]->y2 + $y * $y);
      }
    };
    
    $calcLon = function (&$path) use($mod, $xprod, $sign, $cyclic){
      $n = $path->len; $pt = &$path->pt;
      $pivk = array_fill(0, $n, NULL);
      $nc = array_fill(0, $n, NULL);
      $ct = array_fill(0, 4, NULL);
      $path->lon = array_fill(0, $n, NULL);
      
      $constraint = array(new Point(), new Point());
      $cur = new Point();
      $off = new Point();
      $dk = new Point();
      
      $k = 0;
      for($i = $n - 1; $i >= 0; $i--){
        if ($pt[$i]->x != $pt[$k]->x && $pt[$i]->y != $pt[$k]->y) {
          $k = $i + 1;
        }
        $nc[$i] = $k;
      }
      
      for ($i = $n - 1; $i >= 0; $i--) {
        $ct[0] = $ct[1] = $ct[2] = $ct[3] = 0;
        $dir = (3 + 3 * ($pt[$mod($i + 1, $n)]->x - $pt[$i]->x) + 
                ($pt[$mod($i + 1, $n)]->y - $pt[$i]->y)) / 2;
        $ct[$dir]++;
        
        $constraint[0]->x = 0;
        $constraint[0]->y = 0;
        $constraint[1]->x = 0;
        $constraint[1]->y = 0;
        
        $k = $nc[$i];
        $k1 = $i;
        while (1) {
          $foundk = 0;
          $dir =  (3 + 3 * $sign($pt[$k]->x - $pt[$k1]->x) + 
                   $sign($pt[$k]->y - $pt[$k1]->y)) / 2;
          $ct[$dir]++;
          
          if ($ct[0] && $ct[1] && $ct[2] && $ct[3]) {
            $pivk[$i] = $k1;
            $foundk = 1;
            break;
          }
          
          $cur->x = $pt[$k]->x - $pt[$i]->x;
          $cur->y = $pt[$k]->y - $pt[$i]->y;
          
          if ($xprod($constraint[0], $cur) < 0 || $xprod($constraint[1], $cur) > 0) {
            break;
          }
          
          if (abs($cur->x) <= 1 && abs($cur->y) <= 1) {
            
          } else {
            $off->x = $cur->x + (($cur->y >= 0 && ($cur->y > 0 || $cur->x < 0)) ? 1 : -1);
            $off->y = $cur->y + (($cur->x <= 0 && ($cur->x < 0 || $cur->y < 0)) ? 1 : -1);
            if ($xprod($constraint[0], $off) >= 0) {
              $constraint[0]->x = $off->x;
              $constraint[0]->y = $off->y;
            }
            $off->x = $cur->x + (($cur->y <= 0 && ($cur->y < 0 || $cur->x < 0)) ? 1 : -1);
            $off->y = $cur->y + (($cur->x >= 0 && ($cur->x > 0 || $cur->y < 0)) ? 1 : -1);
            if ($xprod($constraint[1], $off) <= 0) {
              $constraint[1]->x = $off->x;
              $constraint[1]->y = $off->y;
            }
          }
          $k1 = $k;
          $k = $nc[$k1];
          if (!$cyclic($k, $i, $k1)) {
            break;
          }
        }
        if ($foundk == 0) {
          $dk->x = $sign($pt[$k]->x - $pt[$k1]->x);
          $dk->y = $sign($pt[$k]->y - $pt[$k1]->y);
          $cur->x = $pt[$k1]->x - $pt[$i]->x;
          $cur->y = $pt[$k1]->y - $pt[$i]->y;
          
          $a = $xprod($constraint[0], $cur);
          $b = $xprod($constraint[0], $dk);
          $c = $xprod($constraint[1], $cur);
          $d = $xprod($constraint[1], $dk);
          
          $j = 10000000;
          if ($b < 0) {
            $j = floor($a / -$b);
          }
          if ($d > 0) {
            $j = min($j, floor(-$c / $d));
          }
          $pivk[$i] = $mod($k1+$j,$n);
        }
      }
      
      $j=$pivk[$n-1];
      $path->lon[$n-1]=$j;
      for ($i=$n-2; $i>=0; $i--) {
        if ($cyclic($i+1,$pivk[$i],$j)) {
          $j=$pivk[$i];
        }
        $path->lon[$i]=$j;
      }
      
      for ($i=$n-1; $cyclic($mod($i+1,$n),$j,$path->lon[$i]); $i--) {
        $path->lon[$i] = $j;
      }
    };
    
    $bestPolygon = function (&$path) use($mod){
      
      $penalty3 = function ($path, $i, $j) {
        $n = $path->len; $pt = $path->pt; $sums = $path->sums;
        $r = 0;
        if ($j>=$n) {
          $j -= $n;
          $r = 1;
        }
        
        if ($r == 0) {
          $x = $sums[$j+1]->x - $sums[$i]->x;
          $y = $sums[$j+1]->y - $sums[$i]->y;
          $x2 = $sums[$j+1]->x2 - $sums[$i]->x2;
          $xy = $sums[$j+1]->xy - $sums[$i]->xy;
          $y2 = $sums[$j+1]->y2 - $sums[$i]->y2;
          $k = $j+1 - $i;
        } else {
          $x = $sums[$j+1]->x - $sums[$i]->x + $sums[$n]->x;
          $y = $sums[$j+1]->y - $sums[$i]->y + $sums[$n]->y;
          $x2 = $sums[$j+1]->x2 - $sums[$i]->x2 + $sums[$n]->x2;
          $xy = $sums[$j+1]->xy - $sums[$i]->xy + $sums[$n]->xy;
          $y2 = $sums[$j+1]->y2 - $sums[$i]->y2 + $sums[$n]->y2;
          $k = $j+1 - $i + $n;
        } 
        
        $px = ($pt[$i]->x + $pt[$j]->x) / 2.0 - $pt[0]->x;
        $py = ($pt[$i]->y + $pt[$j]->y) / 2.0 - $pt[0]->y;
        $ey = ($pt[$j]->x - $pt[$i]->x);
        $ex = -($pt[$j]->y - $pt[$i]->y);
        
        $a = (($x2 - 2*$x*$px) / $k + $px*$px);
        $b = (($xy - $x*$py - $y*$px) / $k + $px*$py);
        $c = (($y2 - 2*$y*$py) / $k + $py*$py);
        
        $s = $ex*$ex*$a + 2*$ex*$ey*$b + $ey*$ey*$c;
        
        return sqrt($s);
      };
      
      $n = $path->len;
      $pen = array_fill(0, $n + 1, NULL);
      $prev = array_fill(0, $n + 1, NULL);
      $clip0 = array_fill(0, $n, NULL);
      $clip1 = array_fill(0, $n + 1,  NULL);
      $seg0 = array_fill(0, $n + 1, NULL);
      $seg1 = array_fill(0, $n + 1, NULL);
      
      for ($i=0; $i<$n; $i++) {
        $c = $mod($path->lon[$mod($i-1,$n)]-1,$n);
        if ($c == $i) {
          $c = $mod($i+1,$n);
        }
        if ($c < $i) {
          $clip0[$i] = $n;
        } else {
          $clip0[$i] = $c;
        }
      }
      
      $j = 1;
      for ($i=0; $i<$n; $i++) {
        while ($j <= $clip0[$i]) {
          $clip1[$j] = $i;
          $j++;
        }
      }
      
      $i = 0;
      for ($j=0; $i<$n; $j++) {
        $seg0[$j] = $i;
        $i = $clip0[$i];
      }
      $seg0[$j] = $n;
      $m = $j;
      
      $i = $n;
      for ($j=$m; $j>0; $j--) {
        $seg1[$j] = $i;
        $i = $clip1[$i];
      }
      $seg1[0] = 0;
      
      $pen[0]=0;
      for ($j=1; $j<=$m; $j++) {
        for ($i=$seg1[$j]; $i<=$seg0[$j]; $i++) {
          $best = -1;
          for ($k=$seg0[$j-1]; $k>=$clip1[$i]; $k--) {
            $thispen = $penalty3($path, $k, $i) + $pen[$k];
            if ($best < 0 || $thispen < $best) {
              $prev[$i] = $k;
              $best = $thispen;
            }
          }
          $pen[$i] = $best;
        }
      }
      $path->m = $m;
      $path->po = array_fill(0, $m, NULL);
      
      for ($i=$n, $j=$m-1; $i>0; $j--) {
        $i = $prev[$i];
        $path->po[$j] = $i;
      }
    };
    
    $adjustVertices = function (&$path) use($mod, $quadform){
      
      $pointslope = function ($path, $i, $j, &$ctr, &$dir) {
        
        $n = $path->len; $sums = $path->sums;
        $r=0;
        
        while ($j>=$n) {
          $j-=$n;
          $r+=1;
        }
        while ($i>=$n) {
          $i-=$n;
          $r-=1;
        }
        while ($j<0) {
          $j+=$n;
          $r-=1;
        }
        while ($i<0) {
          $i+=$n;
          $r+=1;
        }
        
        $x = $sums[$j+1]->x - $sums[$i]->x + $r * $sums[$n]->x;
        $y = $sums[$j+1]->y - $sums[$i]->y + $r * $sums[$n]->y;
        $x2 = $sums[$j+1]->x2 - $sums[$i]->x2 + $r * $sums[$n]->x2;
        $xy = $sums[$j+1]->xy - $sums[$i]->xy + $r * $sums[$n]->xy;
        $y2 = $sums[$j+1]->y2 - $sums[$i]->y2 + $r * $sums[$n]->y2;
        $k = $j+1-$i+$r*$n;
        
        $ctr->x = $x/$k;
        $ctr->y = $y/$k;
        
        $a = ($x2-$x*$x/$k)/$k;
        $b = ($xy-$x*$y/$k)/$k;
        $c = ($y2-$y*$y/$k)/$k;
        
        $lambda2 = ($a + $c+ sqrt(($a - $c)*($a - $c) + 4 * $b * $b))/2;
        
        $a -= $lambda2;
        $c -= $lambda2;
        
        if (abs($a) >= abs($c)) {
          $l = sqrt($a*$a+$b*$b);
          if ($l!=0) {
            $dir->x = -$b/$l;
            $dir->y = $a/$l;
          }
        } else {
          $l = sqrt($c*$c+$b*$b);
          if ($l!==0) {
            $dir->x = -$c/$l;
            $dir->y = $b/$l;
          }
        }
        if ($l==0) {
          $dir->x = $dir->y = 0; 
        }
      };
      
      $m = $path->m; $po = $path->po; $n = $path->len; $pt = $path->pt;
      $x0 = $path->x0; $y0 = $path->y0;
      $ctr = array_fill(0, $m, NULL); $dir = array_fill(0, $m, NULL);
      $q = array_fill(0, $m, NULL);
      $v = array_fill(0, 3, NULL);
      $s = new Point();
      
      $path->curve = new Curve($m);
      
      for ($i=0; $i<$m; $i++) {
        $j = $po[$mod($i+1,$m)];
        $j = $mod($j-$po[$i],$n)+$po[$i];
        $ctr[$i] = new Point();
        $dir[$i] = new Point();
        $pointslope($path, $po[$i], $j, $ctr[$i], $dir[$i]);
      }
      
      for ($i=0; $i<$m; $i++) {
        $q[$i] = new Quad();
        $d = $dir[$i]->x * $dir[$i]->x + $dir[$i]->y * $dir[$i]->y;
        if ($d == 0.0) {
          for ($j=0; $j<3; $j++) {
            for ($k=0; $k<3; $k++) {
              $q[$i]->data[$j * 3 + $k] = 0;
            }
          }
        } else {
          $v[0] = $dir[$i]->y;
          $v[1] = -$dir[$i]->x;
          $v[2] = - $v[1] * $ctr[$i]->y - $v[0] * $ctr[$i]->x;
          for ($l=0; $l<3; $l++) {
            for ($k=0; $k<3; $k++) {
              $q[$i]->data[$l * 3 + $k] = $v[$l] * $v[$k] / $d;
            }
          }
        }
      }
      
      for ($i=0; $i<$m; $i++) {
        $Q = new Quad();
        $w = new Point();
        
        $s->x = $pt[$po[$i]]->x - $x0;
        $s->y = $pt[$po[$i]]->y - $y0;
        
        $j = $mod($i-1,$m);
        
        for ($l=0; $l<3; $l++) {
          for ($k=0; $k<3; $k++) {
            $Q->data[$l * 3 + $k] = $q[$j]->at($l, $k) + $q[$i]->at($l, $k);
          }
        }
        
        while(1) {
          
          $det = $Q->at(0, 0)*$Q->at(1, 1) - $Q->at(0, 1)*$Q->at(1, 0);
          if ($det != 0) {
            $w->x = (-$Q->at(0, 2)*$Q->at(1, 1) + $Q->at(1, 2)*$Q->at(0, 1)) / $det;
            $w->y = ( $Q->at(0, 2)*$Q->at(1, 0) - $Q->at(1, 2)*$Q->at(0, 0)) / $det;
            break;
          }
          
          if ($Q->at(0, 0)>$Q->at(1, 1)) {
            $v[0] = -$Q->at(0, 1);
            $v[1] = $Q->at(0, 0);
          } else if ($Q->at(1, 1)) {
            $v[0] = -$Q->at(1, 1);
            $v[1] = $Q->at(1, 0);
          } else {
            $v[0] = 1;
            $v[1] = 0;
          }
          $d = $v[0] * $v[0] + $v[1] * $v[1];
          $v[2] = - $v[1] * $s->y - $v[0] * $s->x;
          for ($l=0; $l<3; $l++) {
            for ($k=0; $k<3; $k++) {
              $Q->data[$l * 3 + $k] += $v[$l] * $v[$k] / $d;
            }
          }
        }
        $dx = abs($w->x-$s->x);
        $dy = abs($w->y-$s->y);
        if ($dx <= 0.5 && $dy <= 0.5) {
          $path->curve->vertex[$i] = new Point($w->x+$x0, $w->y+$y0);
          continue;
        }
        
        $min = $quadform($Q, $s);
        $xmin = $s->x;
        $ymin = $s->y;
        
        if ($Q->at(0, 0) != 0.0) {
          for ($z=0; $z<2; $z++) {
            $w->y = $s->y-0.5+$z;
            $w->x = - ($Q->at(0, 1) * $w->y + $Q->at(0, 2)) / $Q->at(0, 0);
            $dx = abs($w->x-$s->x);
            $cand = $quadform($Q, $w);
            if ($dx <= 0.5 && $cand < $min) {
              $min = $cand;
              $xmin = $w->x;
              $ymin = $w->y;
            }
          }
        }
        
        if ($Q->at(1, 1) != 0.0) {
          for ($z=0; $z<2; $z++) {
            $w->x = $s->x-0.5+$z;
            $w->y = - ($Q->at(1, 0) * $w->x + $Q->at(1, 2)) / $Q->at(1, 1);
            $dy = abs($w->y-$s->y);
            $cand = $quadform($Q, $w);
            if ($dy <= 0.5 && $cand < $min) {
              $min = $cand;
              $xmin = $w->x;
              $ymin = $w->y;
            }
          }
        }
        
        for ($l=0; $l<2; $l++) {
          for ($k=0; $k<2; $k++) {
            $w->x = $s->x-0.5+$l;
            $w->y = $s->y-0.5+$k;
            $cand = $quadform($Q, $w);
            if ($cand < $min) {
              $min = $cand;
              $xmin = $w->x;
              $ymin = $w->y;
            }
          }
        }
        
        $path->curve->vertex[$i] = new Point($xmin + $x0, $ymin + $y0);
      }
    };
    
    $reverse = function (&$path) {
      $curve = &$path->curve; $m = &$curve->n; $v = &$curve->vertex;
      
      for ($i=0, $j=$m-1; $i<$j; $i++, $j--) {
        $tmp = $v[$i];
        $v[$i] = $v[$j];
        $v[$j] = $tmp;
      }
    };
    
    $smooth = function (&$path) use($mod, $interval, $ddenom, $dpara, $info) {
        $m = $path->curve->n;
        $curve = &$path->curve;
        
        for ($i = 0; $i < $m; $i++) {
            $j = $mod($i + 1, $m);
            $k = $mod($i + 2, $m);
            $p4 = $interval(1/2.0, $curve->vertex[$k], $curve->vertex[$j]);
            
            $denom = $ddenom($curve->vertex[$i], $curve->vertex[$k]);
            if ($denom != 0.0) {
                $dd = $dpara($curve->vertex[$i], $curve->vertex[$j], $curve->vertex[$k]) / $denom;
                $dd = abs($dd);
                $alpha = $dd > 1 ? (1 - 1.0 / $dd) : 0;
                $alpha = $alpha / 0.75;
            } else {
                $alpha = 4 / 3.0;
            }
            $curve->alpha0[$j] = $alpha;
            
            if ($alpha >= $info->alphamax) {
                $curve->tag[$j] = "CORNER";
                $curve->c[3 * $j + 1] = $curve->vertex[$j];
                $curve->c[3 * $j + 2] = $p4;
            } else {
                if ($alpha < 0.55) {
                    $alpha = 0.55;
                } else if ($alpha > 1) {
                    $alpha = 1;
                }
                $p2 = $interval(0.5 + 0.5 * $alpha, $curve->vertex[$i], $curve->vertex[$j]);
                $p3 = $interval(0.5 + 0.5 * $alpha, $curve->vertex[$k], $curve->vertex[$j]);
                $curve->tag[$j] = "CURVE";
                $curve->c[3 * $j + 0] = $p2;
                $curve->c[3 * $j + 1] = $p3;
                $curve->c[3 * $j + 2] = $p4;
            }
            $curve->alpha[$j] = $alpha;
            $curve->beta[$j] = 0.5;
        }
        $curve->alphaCurve = 1;
    };
    
    $optiCurve = function (&$path) use($mod, $ddist, $sign, $cprod, $dpara, $interval, $tangent, $bezier, $iprod, $iprod1, $info){
      $opti_penalty = function ($path, $i, $j, $res, $opttolerance, $convc, $areac) use($mod, $ddist, $sign, $cprod, $dpara, $interval, $tangent, $bezier, $iprod, $iprod1){
        $m = $path->curve->n; $curve = $path->curve; $vertex = $curve->vertex;
        if ($i==$j) {
          return 1;
        }
        
        $k = $i;
        $i1 = $mod($i+1, $m);
        $k1 = $mod($k+1, $m);
        $conv = $convc[$k1];
        if ($conv == 0) {
          return 1;
        }
        $d = $ddist($vertex[$i], $vertex[$i1]);
        for ($k=$k1; $k!=$j; $k=$k1) {
          $k1 = $mod($k+1, $m);
          $k2 = $mod($k+2, $m);
          if ($convc[$k1] != $conv) {
            return 1;
          }
          if ($sign($cprod($vertex[$i], $vertex[$i1], $vertex[$k1], $vertex[$k2])) !=
            $conv) {
            return 1;
          }
          if ($iprod1($vertex[$i], $vertex[$i1], $vertex[$k1], $vertex[$k2]) <
            $d * $ddist($vertex[$k1], $vertex[$k2]) * -0.999847695156) {
            return 1;
          }
        }
        
        $p0 = clone $curve->c[$mod($i,$m) * 3 + 2];
        $p1 = clone $vertex[$mod($i+1,$m)];
        $p2 = clone $vertex[$mod($j,$m)];
        $p3 = clone $curve->c[$mod($j,$m) * 3 + 2];
        
        $area = $areac[$j] - $areac[$i];
        $area -= $dpara($vertex[0], $curve->c[$i * 3 + 2], $curve->c[$j * 3 + 2])/2;
        if ($i>=$j) {
          $area += $areac[$m];
        }
        
        $A1 = $dpara($p0, $p1, $p2);
        $A2 = $dpara($p0, $p1, $p3);
        $A3 = $dpara($p0, $p2, $p3);
        
        $A4 = $A1+$A3-$A2;    
        
        if ($A2 == $A1) {
          return 1;
        }
        
        $t = $A3/($A3-$A4);
        $s = $A2/($A2-$A1);
        $A = $A2 * $t / 2.0;
        
        if ($A == 0.0) {
          return 1;
        }
        
        $R = $area / $A;
        $alpha = 2 - sqrt(4 - $R / 0.3);
        
        $res->c[0] = $interval($t * $alpha, $p0, $p1);
        $res->c[1] = $interval($s * $alpha, $p3, $p2);
        $res->alpha = $alpha;
        $res->t = $t;
        $res->s = $s;
        
        $p1 = clone $res->c[0];
        $p2 = clone $res->c[1]; 
        
        $res->pen = 0;
        
        for ($k=$mod($i+1,$m); $k!=$j; $k=$k1) {
          $k1 = $mod($k+1,$m);
          $t = $tangent($p0, $p1, $p2, $p3, $vertex[$k], $vertex[$k1]);
          if ($t<-0.5) {
            return 1;
          }
          $pt = $bezier($t, $p0, $p1, $p2, $p3);
          $d = $ddist($vertex[$k], $vertex[$k1]);
          if ($d == 0.0) {
            return 1;
          }
          $d1 = $dpara($vertex[$k], $vertex[$k1], $pt) / $d;
          if (abs($d1) > $opttolerance) {
            return 1;
          }
          if ($iprod($vertex[$k], $vertex[$k1], $pt) < 0 ||
              $iprod($vertex[$k1], $vertex[$k], $pt) < 0) {
            return 1;
          }
          $res->pen += $d1 * $d1;
        }
        
        for ($k=$i; $k!=$j; $k=$k1) {
          $k1 = $mod($k+1,$m);
          $t = $tangent($p0, $p1, $p2, $p3, $curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2]);
          if ($t<-0.5) {
            return 1;
          }
          $pt = $bezier($t, $p0, $p1, $p2, $p3);
          $d = $ddist($curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2]);
          if ($d == 0.0) {
            return 1;
          }
          $d1 = $dpara($curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2], $pt) / $d;
          $d2 = $dpara($curve->c[$k * 3 + 2], $curve->c[$k1 * 3 + 2], $vertex[$k1]) / $d;
          $d2 *= 0.75 * $curve->alpha[$k1];
          if ($d2 < 0) {
            $d1 = -$d1;
            $d2 = -$d2;
          }
          if ($d1 < $d2 - $opttolerance) {
            return 1;
          }
          if ($d1 < $d2) {
            $res->pen += ($d1 - $d2) * ($d1 - $d2);
          }
        }
        
        return 0;
      };
      
      $curve = $path->curve; $m = $curve->n; $vert = $curve->vertex;
      $pt = array_fill(0, $m + 1, NULL);
      $pen = array_fill(0, $m + 1, NULL);
      $len = array_fill(0, $m + 1, NULL);
      $opt = array_fill(0, $m + 1, NULL);
      $o = new Opti();
      
      $convc = array_fill(0, $m, NULL); $areac = array_fill(0, $m + 1, NULL);
      
      for ($i=0; $i<$m; $i++) {
        if ($curve->tag[$i] == "CURVE") {
          $convc[$i] = $sign($dpara($vert[$mod($i-1,$m)], $vert[$i], $vert[$mod($i+1,$m)]));
        } else {
          $convc[$i] = 0;
        }
      }
      
      $area = 0.0;
      $areac[0] = 0.0;
      $p0 = $curve->vertex[0];
      for ($i=0; $i<$m; $i++) {
        $i1 = $mod($i+1, $m);
        if ($curve->tag[$i1] == "CURVE") {
          $alpha = $curve->alpha[$i1];
          $area += 0.3 * $alpha * (4-$alpha) *
          $dpara($curve->c[$i * 3 + 2], $vert[$i1], $curve->c[$i1 * 3 + 2])/2;
          $area += $dpara($p0, $curve->c[$i * 3 + 2], $curve->c[$i1 * 3 + 2])/2;
        }
        $areac[$i+1] = $area;
      }
      
      $pt[0] = -1;
      $pen[0] = 0;
      $len[0] = 0;
      
      
      for ($j=1; $j<=$m; $j++) {
        $pt[$j] = $j-1;
        $pen[$j] = $pen[$j-1];
        $len[$j] = $len[$j-1]+1;
        
        for ($i=$j-2; $i>=0; $i--) {
          $r = $opti_penalty($path, $i, $mod($j,$m), $o, $info->opttolerance, $convc, 
                             $areac);
          if ($r) {
            break;
          }
          if ($len[$j] > $len[$i]+1 ||
              ($len[$j] == $len[$i]+1 && $pen[$j] > $pen[$i] + $o->pen)) {
            $pt[$j] = $i;
            $pen[$j] = $pen[$i] + $o->pen;
            $len[$j] = $len[$i] + 1;
            $opt[$j] = $o;
            $o = new Opti();
          }
        }
      }
      $om = $len[$m];
      $ocurve = new Curve($om);
      $s = array_fill(0, $om, NULL);
      $t = array_fill(0, $om, NULL);
      
      $j = $m;
      for ($i=$om-1; $i>=0; $i--) {
        if ($pt[$j]==$j-1) {
          $ocurve->tag[$i]     = $curve->tag[$mod($j,$m)];
          $ocurve->c[$i * 3 + 0]    = $curve->c[$mod($j,$m) * 3 + 0];
          $ocurve->c[$i * 3 + 1]    = $curve->c[$mod($j,$m) * 3 + 1];
          $ocurve->c[$i * 3 + 2]    = $curve->c[$mod($j,$m) * 3 + 2];
          $ocurve->vertex[$i]  = $curve->vertex[$mod($j,$m)];
          $ocurve->alpha[$i]   = $curve->alpha[$mod($j,$m)];
          $ocurve->alpha0[$i]  = $curve->alpha0[$mod($j,$m)];
          $ocurve->beta[$i]    = $curve->beta[$mod($j,$m)];
          $s[$i] = $t[$i] = 1.0;
        } else {
          $ocurve->tag[$i] = "CURVE";
          $ocurve->c[$i * 3 + 0] = $opt[$j]->c[0];
          $ocurve->c[$i * 3 + 1] = $opt[$j]->c[1];
          $ocurve->c[$i * 3 + 2] = $curve->c[$mod($j,$m) * 3 + 2];
          $ocurve->vertex[$i] = $interval($opt[$j]->s, $curve->c[$mod($j,$m) * 3 + 2],
                                          $vert[$mod($j,$m)]);
          $ocurve->alpha[$i] = $opt[$j]->alpha;
          $ocurve->alpha0[$i] = $opt[$j]->alpha;
          $s[$i] = $opt[$j]->s;
          $t[$i] = $opt[$j]->t;
        }
        $j = $pt[$j];
      }
      
      for ($i=0; $i<$om; $i++) {
        $i1 = $mod($i+1,$om);
        if(($s[$i] + $t[$i1]) != 0){
          $ocurve->beta[$i] = $s[$i] / ($s[$i] + $t[$i1]);
        }else{
          $ocurve->beta[$i] = 0.5; 
        }
      }
      $ocurve->alphaCurve = 1;
      $path->curve = $ocurve;	  
    };

    for ($i = 0; $i < count($this->pathlist); $i++) {
      $path = &$this->pathlist[$i];
  
      $calcSums($path);
      $calcLon($path);
      $bestPolygon($path);
      $adjustVertices($path);
      
      if ($path->sign === "-") {
        $reverse($path);
      }
      
      $smooth($path);
      
      if ($info->optcurve) {
        $optiCurve($path);
      }
	  
	  // Validate curve
      if(!isset($path->curve->tag) || !is_array($path->curve->tag) || count($path->curve->tag) != $path->curve->n){    			
            // Initialize tag array if missing
            $path->curve->tag = array_fill(0, $path->curve->n, "CURVE");
       }
		
    }
  }
  
  public function process() {
    $this->bmToPathlist();
    $this->processPath();
  }

  public function clear() {
    $this->bm = null;
    $this->pathlist = array();
  }
  
  
  //SVG Backend
  public function getSVG($size, $opt_type='', $coord_mode = 'absolute') {
		$bm = &$this->bm;
		$pathlist = &$this->pathlist;
		$ptScale = $this->info->unit;
		
		// choose coordinate mode: 'absolute' (default) or 'relative'
			
		if ($opt_type === 'relative' || $opt_type === 'rel') $coord_mode = 'relative';
		if (!empty($this->info->coord) && $this->info->coord === 'relative') $coord_mode = 'relative';
							
		// Path string generator (absolute/relative)
		$pathStr = function($curve, $sz = null, $ht = null) use ($size, $bm, $coord_mode) {
			
			$ptScale = $this->info->unit;
			$sz = ($sz === null) ? $size : $sz;
			$ht = ($ht === null) ? (int) round($bm->h) : $ht;

			$n = $curve->n ?? 0;
			if ($n <= 0 || !isset($curve->c) || count($curve->c) < $n*3) {
				return '';
			}

			$sx = (int) round($curve->c[($n-1)*3 + 2]->x * $sz * $ptScale); 
			$sy = (int) round(($ht - $curve->c[($n-1)*3 + 2]->y) * $sz * $ptScale); 
				
			$pathfinal = "M%d %d ";
				
			$str = sprintf($pathfinal, $sx, $sy);
			$cx = $sx;
			$cy = $sy;

			for ($i = 0; $i < $n; $i++) {
				
				if (!isset($curve->c[$i*3+2])) {
					continue;
				}
				
				$tag = isset($curve->tag[$i]) ? $curve->tag[$i] : 'CURVE';
				
				if ($tag === 'CURVE') {
					
					if (!isset($curve->c[$i*3+0]) || !isset($curve->c[$i*3+1])) {
						continue;
					}
					
					$c1x = (int) round($curve->c[$i*3+0]->x * $sz * $ptScale);
					$c1y = (int) round(($ht - $curve->c[$i*3+0]->y) * $sz * $ptScale);
					$c2x = (int) round($curve->c[$i*3+1]->x * $sz * $ptScale);
					$c2y = (int) round(($ht - $curve->c[$i*3+1]->y) * $sz * $ptScale);
					$ex = (int) round($curve->c[$i*3+2]->x * $sz * $ptScale);
					$ey = (int) round(($ht - $curve->c[$i*3+2]->y) * $sz * $ptScale);

					// Skip if control points are degenerate (same as endpoint)
					if (abs($c1x - $ex) < 0.001 && abs($c1y - $ey) < 0.001 && 
						abs($c2x - $ex) < 0.001 && abs($c2y - $ey) < 0.001) {
						continue;
					}

					if ($coord_mode === 'relative') {
						$r1x = $c1x - $cx;
						$r1y = $c1y - $cy;
						$r2x = $c2x - $cx;
						$r2y = $c2y - $cy;
						$r3x = $ex - $cx;
						$r3y = $ey - $cy;
								
						$pathfinal = "c%d %d %d %d %d %d "; 
								
						$str .= sprintf($pathfinal, $r1x, $r1y, $r2x, $r2y, $r3x, $r3y);
						
					} else {
								
						$pathfinal = "C%d %d %d %d %d %d ";
								
						$str .= sprintf($pathfinal, $c1x, $c1y, $c2x, $c2y, $ex, $ey);
					}
					
					$cx = $ex;
					$cy = $ey;
					
				} else {
					
					if (!isset($curve->c[$i*3+1])) {
						continue;
					}
					$x1 = (int) round($curve->c[$i*3+1]->x * $sz * $ptScale);
					$y1 = (int) round(($ht - $curve->c[$i*3+1]->y) * $sz * $ptScale);
					$x2 = (int) round($curve->c[$i*3+2]->x * $sz * $ptScale);
					$y2 = (int) round(($ht - $curve->c[$i*3+2]->y) * $sz * $ptScale);

					// Skip if points are degenerate
					if (abs($x1 - $cx) < 0.001 && abs($y1 - $cy) < 0.001 && 
						abs($x2 - $cx) < 0.001 && abs($y2 - $cy) < 0.001) {
							continue;
					}

					if ($coord_mode === 'relative') {
						$r1x = $x1 - $cx;
						$r1y = $y1 - $cy;
						$r2x = $x2 - $x1;
						$r2y = $y2 - $y1;
								
						$pathfinal = "l%d %d %d %d ";
						$str .= sprintf($pathfinal, $r1x, $r1y, $r2x, $r2y);
						
					} else {
						$pathfinal = "L%d %d %d %d ";
						$str .= sprintf($pathfinal, $x1, $y1, $x2, $y2);
					}
					$cx = $x2;
					$cy = $y2;
				}
			}
			$str .= 'z';
			
			return $str;
		};

		// Get output dimensions with margins
		list($w, $h, $baseW, $baseH) = $this->getOutputDimensions($size);
		$scaleW = $baseW / $bm->w;
		$scaleH = $baseH / $bm->h;
			
		/* header */
		$svg = "<?xml version=\"1.0\" standalone=\"no\"?>\n";
		$svg .= "<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 20010904//EN\"\n";
		$svg .= " \"http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd\">\n";
			
		$ptScale = $this->info->unit;
		
		/* set bounding box and namespace */
		$svg .=  "<svg version=\"1.0\" xmlns=\"http://www.w3.org/2000/svg\"\n";
		$svg .= " width=\"". $w . ".000000pt\" height=\"". $h .".000000pt\" viewBox=\"0 0 ". $w .".000000 ". $h .".000000\"\n";
		$svg .= " preserveAspectRatio=\"xMidYMid meet\">\n";

		/* metadata */
		$svg .= "<metadata>\nCreated by potrace 1.16, written by Peter Selinger 2001-2019. PHP Port by Savoul Pelister 2025.\n</metadata>\n";
			
		if($this->info->bg)
			$svg .= "<rect width=\"" . $w . "\" height=\"". $h ."\" fill=\"#ffffff\"></rect>\n";

			if ($opt_type === 'curve') {
				$strokec = 'black';
				$fillc = 'none';
				$extraFillRule = '';
			} else {
				$strokec = 'none';
				$fillc = 'black';
				$extraFillRule = ' fill-rule="evenodd"';
			}

			$flat = !empty($this->info->flat);
			$group = !empty($this->info->group) && !$flat;

			// open transform group (this flips Y-axis like potrace)
			
			// Apply margins through transform
			$margins = $this->info->margins;
			$transformX = $margins['left'];
			$transformY = $h - $margins['bottom'];
			
			$fillcolor = $this->info->fillcolor;
			$linecolor = $this->info->linecolor;
			
			// ---------- FLAT MODE ----------
			if ($flat) {
				
				$svg .= '<g transform="translate('. $transformX .','. $transformY .') scale('. $scaleW/$ptScale .',-'. $scaleH/$ptScale .')" stroke="none">'."\n";
				
				foreach ($pathlist as $pl) {
					$fill = (isset($pl->sign) && $pl->sign==='-') ? $fillcolor : $linecolor;
					$svg .= '<path fill="'.$fill.'" stroke="none" d="'.$pathStr($pl->curve, $size, (int)round($bm->h)).'"/>'."\n";
				}
				$svg .= "</g>\n</svg>";
				return $svg;
			}
			
			// --- GROUP MODE ---
			if ($group) {

				$fillcolor = $this->info->fillcolor ?: '#ffffff'; // holes (Potrace group uses bg color)
				$linecolor = $this->info->linecolor ?: '#000000'; // solids

				$paths = array_values($pathlist);
				$n = count($paths);

				// ---------- geometry on the traced polygon (path->pt), not on Beziers ----------
				$polyArea = function(array $pts): float {
					$m = count($pts);
					if ($m < 3) return 0.0;
					$a = 0.0;
					for ($i = 0; $i < $m; $i++) {
						$j = ($i + 1) % $m;
						$a += $pts[$i]->x * $pts[$j]->y - $pts[$j]->x * $pts[$i]->y;
					}
					return $a / 2.0;
				};

				$pointInPoly = function(float $x, float $y, array $pts): bool {
					$m = count($pts);
					if ($m < 3) return false;

					$inside = false;
					$j = $m - 1;
					for ($i = 0; $i < $m; $i++) {
						$xi = $pts[$i]->x; $yi = $pts[$i]->y;
						$xj = $pts[$j]->x; $yj = $pts[$j]->y;

						// Ray casting
						$intersect = (($yi > $y) !== ($yj > $y)) &&
							($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi);

						if ($intersect) $inside = !$inside;
						$j = $i;
					}
					return $inside;
				};

				// Pick a point that is actually inside the polygon:
				// take a segment midpoint and move a small epsilon along an inward normal.
				$insidePoint = function($path) use ($polyArea, $pointInPoly): array {
					$pts = $path->pt ?? [];
					$m = count($pts);
					if ($m < 2) return [0.0, 0.0];

					$A = $polyArea($pts);
					$eps = 0.25; // in pixel units; big enough to avoid boundary issues

					for ($i = 0; $i < $m; $i++) {
						$j = ($i + 1) % $m;

						$x1 = $pts[$i]->x; $y1 = $pts[$i]->y;
						$x2 = $pts[$j]->x; $y2 = $pts[$j]->y;
						$dx = $x2 - $x1;   $dy = $y2 - $y1;
						if ($dx == 0 && $dy == 0) continue;

						// normal choice depends on polygon orientation
						// CCW: interior is to the left -> (-dy, dx)
						// CW : interior is to the right -> (dy, -dx)
						if ($A > 0) { $nx = -$dy; $ny = $dx; }
						else        { $nx =  $dy; $ny = -$dx; }

						$len = hypot($nx, $ny);
						if ($len == 0.0) continue;
						$nx /= $len; $ny /= $len;

						$mx = ($x1 + $x2) / 2.0;
						$my = ($y1 + $y2) / 2.0;

						$c1 = [$mx + $nx * $eps, $my + $ny * $eps];
						if ($pointInPoly($c1[0], $c1[1], $pts)) return $c1;

						$c2 = [$mx - $nx * $eps, $my - $ny * $eps];
						if ($pointInPoly($c2[0], $c2[1], $pts)) return $c2;
					}

					// fallback
					return [$pts[0]->x + 0.3, $pts[0]->y + 0.3];
				};

				// Normalize sign just in case
				$normSign = function($s): string {
					if ($s === '-') return '-';
					if ($s === '+') return '+';
					if (is_numeric($s)) return ((float)$s < 0) ? '-' : '+';
					$s = (string)$s;
					return ($s !== '' && $s[0] === '-') ? '-' : '+';
				};

				// Precompute metrics
				$sign = [];
				$absArea = [];
				$testPt = [];

				for ($i = 0; $i < $n; $i++) {
					$sign[$i] = $normSign($paths[$i]->sign ?? '+');
					// prefer traced area if present, else polygon area
					$absArea[$i] = abs($paths[$i]->area ?? 0);
					if ($absArea[$i] <= 0) $absArea[$i] = abs($polyArea($paths[$i]->pt ?? []));
					$testPt[$i] = $insidePoint($paths[$i]);
				}

				// Sort indices by area ascending once; parent = first larger-area container
				$order = range(0, $n - 1);
				usort($order, function($a, $b) use ($absArea) {
					return $absArea[$a] <=> $absArea[$b];
				});

				// Build parent pointers (smallest opposite-sign container)
				$parent = array_fill(0, $n, -1);

				for ($ii = 0; $ii < $n; $ii++) {
					$i = $ii;
					[$x, $y] = $testPt[$i];

					foreach ($order as $j) {
						if ($j === $i) continue;
						if ($absArea[$j] <= $absArea[$i]) continue;
						if ($sign[$j] === $sign[$i]) continue;

						// bbox quick reject (use strict bounds; add a tiny epsilon)
						$e = 1e-6;
						if ($x <= $paths[$j]->minX + $e || $x >= $paths[$j]->maxX - $e ||
							$y <= $paths[$j]->minY + $e || $y >= $paths[$j]->maxY - $e) {
							continue;
						}

						if ($pointInPoly($x, $y, $paths[$j]->pt ?? [])) {
							$parent[$i] = $j;
							break; // smallest container by ascending area
						}
					}
				}

				// children lists in original order (keeps Potrace-like stacking)
				$children = array_fill(0, $n, []);
				for ($i = 0; $i < $n; $i++) {
					if ($parent[$i] >= 0) {
						$children[$parent[$i]][] = $i;
					}
				}

				// SVG transform same as flat/default
				$svg .= '<g transform="translate('.$transformX.','. $transformY .') scale('.($scaleW/$ptScale).',-'.($scaleH/$ptScale).')" stroke="none">'."\n";

				$renderNode = function($i) use (&$renderNode, $children, $paths, $sign, $fillcolor, $linecolor, $pathStr, $size, $bm) {
					$d = $pathStr($paths[$i]->curve, $size, (int)round($bm->h));
					if ($d === '') return '';

					$fill = ($sign[$i] === '-') ? $fillcolor : $linecolor;

					$out  = "<g>\n";
					$out .= '<path fill="'.$fill.'" stroke="none" d="'.$d.'"/>'."\n";
					foreach ($children[$i] as $ch) {
						$out .= $renderNode($ch);
					}
					$out .= "</g>\n";
					return $out;
				};

				for ($i = 0; $i < $n; $i++) {
					if ($parent[$i] < 0) {
						$svg .= $renderNode($i);
					}
				}

				$svg .= "</g>\n</svg>";
				return $svg;
			}
			
			// ---------- DEFAULT single path ----------
			
			$dAll = '';
			foreach ($pathlist as $pl) {
				$dAll .= $pathStr($pl->curve) . ' ';
			}
			$svg .= '<g transform="translate('.$transformX.','. $transformY .') ';
			$svg .= 'scale('.$scaleW/$ptScale.',-'.$scaleH/$ptScale.')">' . "\n";
			$svg .= '<path d="'.$dAll.'" stroke="'.$strokec.'" fill="'.$fillc.'"'.$extraFillRule.'/>'."\n";
			$svg .= "</g>\n</svg>";
			return $svg; 
		
    }
	
	// EPS Backend
  public function getEPS($size=1) {
    $bm = &$this->bm;
    $pathlist = &$this->pathlist;
    
    // Get output dimensions with margins
    list($w, $h, $baseW, $baseH) = $this->getOutputDimensions($size);
    $scaleW = $baseW / $bm->w;
    $scaleH = $baseH / $bm->h;
    
    $margins = $this->info->margins;
    $transformX = $margins['left'];
    $transformY = $margins['top'];
    
    $eps = "%!PS-Adobe-3.0 EPSF-3.0\n";
    $eps .= "%%BoundingBox: 0 0 $w $h\n";
    $eps .= "%%HiResBoundingBox: 0.0 0.0 $w.0 $h.0\n";
    $eps .= "%%Creator: POtracePHP\n";
    $eps .= "%%Title: Vectorized Image\n";
    $eps .= "%%EndComments\n";
    $eps .= "/m {moveto} bind def\n";
    $eps .= "/l {lineto} bind def\n";
    $eps .= "/c {curveto} bind def\n";
    $eps .= "/z {closepath} bind def\n";
    $eps .= "/f {fill} bind def\n";
    
    // Apply margins and coordinate transform
    $eps .= "gsave\n";
    $eps .= "$transformX $transformY translate\n";
    $eps .= "$scaleW $scaleH scale\n";
    $eps .= "0 0 translate\n";
    $eps .= "1 -1 scale\n";
    $eps .= "0 -$bm->h translate\n";
    
    // Set proper fill color for paths (black fill)
    $eps .= "0 setgray\n";
    $eps .= "newpath\n";
    
    foreach ($pathlist as $pl) {
        $curve = $pl->curve;
        $n = $curve->n;
        
        if ($n <= 0) continue;
        
        // Start path
        $startX = $curve->c[($n-1)*3 + 2]->x;
        $startY = $curve->c[($n-1)*3 + 2]->y;
        $eps .= sprintf("%.3f %.3f m\n", $startX, $startY);
        
        // Add segments
        for ($i = 0; $i < $n; $i++) {
            $tag = isset($curve->tag[$i]) ? $curve->tag[$i] : 'CURVE';
            
            if ($tag === 'CURVE') {
                if (isset($curve->c[$i*3+0]) && isset($curve->c[$i*3+1]) && isset($curve->c[$i*3+2])) {
                    $c1x = $curve->c[$i*3+0]->x;
                    $c1y = $curve->c[$i*3+0]->y;
                    $c2x = $curve->c[$i*3+1]->x;
                    $c2y = $curve->c[$i*3+1]->y;
                    $ex = $curve->c[$i*3+2]->x;
                    $ey = $curve->c[$i*3+2]->y;
                    $eps .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $c1x, $c1y, $c2x, $c2y, $ex, $ey);
                }
            } else {
                if (isset($curve->c[$i*3+1]) && isset($curve->c[$i*3+2])) {
                    $x1 = $curve->c[$i*3+1]->x;
                    $y1 = $curve->c[$i*3+1]->y;
                    $x2 = $curve->c[$i*3+2]->x;
                    $y2 = $curve->c[$i*3+2]->y;
                    $eps .= sprintf("%.3f %.3f l %.3f %.3f l\n", $x1, $y1, $x2, $y2);
                }
            }
        }
        
        $eps .= "z\n";  // Close path
    }
    
    $eps .= "f\n";  // Fill all paths
    $eps .= "grestore\n";
    $eps .= "%%EOF\n";
    
    return $eps;
  }

  // PDF Backend
  public function getPDF($size=1) {
    $bm = &$this->bm;
    $pathlist = &$this->pathlist;
    
    // Get output dimensions with margins
    list($w, $h, $baseW, $baseH) = $this->getOutputDimensions($size);
    $scaleW = $baseW / $bm->w;
    $scaleH = $baseH / $bm->h;
    
    $margins = $this->info->margins;
    $transformX = $margins['left'];
    $transformY = $margins['top'];
    
    $pdf = "%PDF-1.4\n";
    
    // Object 1: Catalog
    $pdf .= "1 0 obj\n";
    $pdf .= "<< /Type /Catalog /Pages 2 0 R >>\n";
    $pdf .= "endobj\n";
    
    // Object 2: Pages
    $pdf .= "2 0 obj\n";
    $pdf .= "<< /Type /Pages /Kids [3 0 R] /Count 1 >>\n";
    $pdf .= "endobj\n";
    
    // Object 3: Page
    $pdf .= "3 0 obj\n";
    $pdf .= "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Contents 4 0 R /Resources << >> >>\n";
    $pdf .= "endobj\n";
    
    // Object 4: Content Stream
    $content = "q\n";
    $content .= "1 0 0 1 $transformX $transformY cm\n";  // Apply margins
    $content .= "$scaleW 0 0 $scaleH 0 0 cm\n";  // Apply scaling
    $content .= "1 0 0 -1 0 $bm->h cm\n";  // Flip Y axis
    $content .= "0 0 0 rg\n";  // Set black fill color
    
    // Draw all paths
    foreach ($pathlist as $pl) {
        $curve = $pl->curve;
        $n = $curve->n;
        
        if ($n <= 0) continue;
        
        // Start path
        $startX = $curve->c[($n-1)*3 + 2]->x;
        $startY = $curve->c[($n-1)*3 + 2]->y;
        $content .= sprintf("%.3f %.3f m\n", $startX, $startY);
        
        // Add segments
        for ($i = 0; $i < $n; $i++) {
            $tag = isset($curve->tag[$i]) ? $curve->tag[$i] : 'CURVE';
            
            if ($tag === 'CURVE') {
                if (isset($curve->c[$i*3+0]) && isset($curve->c[$i*3+1]) && isset($curve->c[$i*3+2])) {
                    $c1x = $curve->c[$i*3+0]->x;
                    $c1y = $curve->c[$i*3+0]->y;
                    $c2x = $curve->c[$i*3+1]->x;
                    $c2y = $curve->c[$i*3+1]->y;
                    $ex = $curve->c[$i*3+2]->x;
                    $ey = $curve->c[$i*3+2]->y;
                    $content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $c1x, $c1y, $c2x, $c2y, $ex, $ey);
                }
            } else {
                if (isset($curve->c[$i*3+1]) && isset($curve->c[$i*3+2])) {
                    $x1 = $curve->c[$i*3+1]->x;
                    $y1 = $curve->c[$i*3+1]->y;
                    $x2 = $curve->c[$i*3+2]->x;
                    $y2 = $curve->c[$i*3+2]->y;
                    $content .= sprintf("%.3f %.3f l %.3f %.3f l\n", $x1, $y1, $x2, $y2);
                }
            }
        }
        
        $content .= "h\n";  // Close path
    }
    
    $content .= "f\n";  // Fill all paths
    $content .= "Q\n";
    
    $pdf .= "4 0 obj\n";
    $pdf .= "<< /Length " . strlen($content) . " >>\n";
    $pdf .= "stream\n";
    $pdf .= $content;
    $pdf .= "endstream\n";
    $pdf .= "endobj\n";
    
    // Cross-reference table
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n";
    $pdf .= "0 5\n";
    $pdf .= "0000000000 65535 f \n";
    $pdf .= "0000000010 00000 n \n";
    $pdf .= "0000000057 00000 n \n";
    $pdf .= "0000000114 00000 n \n";
    $pdf .= "0000000193 00000 n \n";
    $pdf .= "trailer\n";
    $pdf .= "<< /Size 5 /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xrefPos . "\n";
    $pdf .= "%%EOF\n";
    
    return $pdf;
  }
  
    
  
  // Get actual output dimensions considering margins and sizing
  private function getOutputDimensions($size) {
    $bm = &$this->bm;
    $margins = $this->info->margins;
    
    // Base dimensions
    $baseW = $bm->w;
    $baseH = $bm->h;
    
    // Apply custom width/height if specified (maintain aspect ratio)
    if ($this->info->width !== null && $this->info->height !== null) {
        $baseW = $this->info->width;
        $baseH = $this->info->height;
    } else if ($this->info->width !== null) {
        $ratio = $this->info->width / $bm->w;
        $baseW = $this->info->width;
        $baseH = $bm->h * $ratio;
    } else if ($this->info->height !== null) {
        $ratio = $this->info->height / $bm->h;
        $baseH = $this->info->height;
        $baseW = $bm->w * $ratio;
    }
    
    // Apply size scaling
    $w = (int) round($baseW * $size);
    $h = (int) round($baseH * $size);
    
    // Add margins
    $w += $margins['left'] + $margins['right'];
    $h += $margins['top'] + $margins['bottom'];
    
    return array($w, $h, $baseW, $baseH);
  }
  
  // Helper method to parse color
  private function parseColor($color) {
	if (strpos($color, '#') === 0) {
	  $hex = substr($color, 1);
	  if (strlen($hex) == 3) {
		$r = hexdec(substr($hex, 0, 1)) / 15;
		$g = hexdec(substr($hex, 1, 1)) / 15;
		$b = hexdec(substr($hex, 2, 1)) / 15;
	  } else {
		$r = hexdec(substr($hex, 0, 2)) / 255;
		$g = hexdec(substr($hex, 2, 2)) / 255;
		$b = hexdec(substr($hex, 4, 2)) / 255;
	  }
	  return array($r, $g, $b);
	}
	return array(0, 0, 0); // Default to black
  }

  
}