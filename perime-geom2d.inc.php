<?php
/*PhpDoc:
name:  geom2d.inc.php
title: geom2d.inc.php - géométrie 2D
includes: [ bbox.inc.php ]
functions:
classes:
doc: |
  Fonctions simples de gestion de la géométrie 2D:
  - création d'un Point, LineString ou Polygon et affichage
  - définition de la précision (cad le nbre de chiffres aoprès la virgule à conserver)
    et d'un filtre pour supprimer dans LineString les points successifs identiques
  - calcul du rectangle englobant, de la distance entre points
  - changement de système de coordonnées, utilise la méthode CoordSys::chg($src, $dest, $x, $y) qui doit être définie à l'utilisation
journal: |
  5/12/2016:
  - ajout d'une méthode geojsonGeometry() sur la classe Geometry
  - ajout d'une méthode coordinates() sur chacune des classes concrètes
  12/11/2016:
  - ajout d'une méthode filter() pour éviter d'avoir 2 points identiques
  25-26/6/2016
  - intégration du chgt de syst. de coord.
  - chgt de logique sur le paramètre precision qui n'arrondit pas à la création mais à l'affichage.
  - suppression du paramètre filter
  9/6/2016
  - amélioration de la gestion du z
  5/6/2016
  - amélioration de la gestion du Point null
  2/6/2016
  - scission de bbox
  30/5-1/6/2016
  - première version
*/
require_once 'bbox.inc.php';

/*PhpDoc: classes
name:  Geometry
title: abstract class Geometry - Sur-classe abstraite des 3 classes Point, LineString et Polygon
methods:
doc: |
  Porte en variable de classe le paramètre precision qui définit le nombre de chiffres après la virgule à afficher par défaut.
  S'il est négatif, il indique le nbre de 0 à afficher comme derniers chiffres.
*/
abstract class Geometry {
  static $precision = null; // nombre de chiffres après la virgule, si null pas d'arrondi
  protected $geom; /* La structure du stockage dépend de la sous-classe
                      Point : ['x':x, 'y':y{, 'z'=>z}]
                      LineString: [ Point ]
                      Polygon: [ LineString ]
                    */  
/*PhpDoc: methods
name:  setParam
title: static function setParam($param, $value=null) - définit un des paramètres
*/
  static function setParam($param, $value=null) {
    switch($param) {
      case 'precision': self::$precision = $value; break;
      default:
        throw new Exception("Parametre non reconnu dans Geometry::setParam()");  
    }
  }
    
/*PhpDoc: methods
name:  create
title: static function create($param) - crée une géométrie à partir d'un WKT
*/
  static function create($param) {
//    echo "Geometry::create(param=",(is_array($param)? "[x=$param[x] y=$param[y]]" : $param),")\n";
    if (preg_match('!^(POINT\s*\()?([-\d.e]+)\s+([-\d.e]+)(\s+([-\d.e]+))?\)?$!', $param))
      return new Point($param);
    elseif (preg_match('!^(LINESTRING\s*)?\(\s*([-\d.e]+)\s+([-\d.e]+)(\s+([-\d.e]+))?\s*,?!', $param))
      return new LineString($param);
    elseif (preg_match('!^(POLYGON\s*)?\(\(\s*([-\d.e]+)\s*([-\d.e]+)\s*,?!', $param))
      return new Polygon($param);
    else
      throw new Exception("Parametre non reconnu dans Geometry::create()");  
  }
  
/*PhpDoc: methods
name:  value
title: function value()
*/
  function value() { return $this->geom; }
  
/*PhpDoc: methods
name:  geojsonGeometry
title: function geojsonGeometry() - retourne un tableau Php qui encodé en JSON correspondra à la geometry GeoJSON
*/
  function geojsonGeometry() { return [ 'type'=>get_called_class(), 'coordinates'=>$this->coordinates() ]; }
};

/*PhpDoc: classes
name:  Point
title: Class Point extends Geometry - Définition d'un Point (OGC)
methods:
doc: |
  protected $geom; // Pour un Point: ['x':x, 'y':y{, 'z'=>z}]
  x et y sont toujours des nombres
*/
class Point extends Geometry {
/*PhpDoc: methods
name:  __construct
title: __construct($param) - construction à partir d'un WKT ou d'un ['x'=>x, 'y'=>y]
*/
  function __construct($param=null) {
//    echo "Point::__construct(param=",(is_array($param)? "[x=$param[x] y=$param[y]]" : $param),")\n";
    if (!is_array($param) and !is_string($param))
      throw new Exception("Parametre non reconnu dans Point::__construct()");
    if (is_array($param))
      $this->geom = $param;
    elseif (is_string($param) and !preg_match('!^(POINT\s*\()?([-\d.e]+)\s+([-\d.e]+)(\s+([-\d.e]+))?\)?$!', $param, $matches))
      throw new Exception("Parametre non reconnu dans Point::__construct()");
    elseif (isset($matches[4]))
      $this->geom = ['x'=>$matches[2], 'y'=>$matches[3], 'z'=>$matches[5]];
    else
      $this->geom = ['x'=>$matches[2], 'y'=>$matches[3]];
  }
    
/*PhpDoc: methods
name:  x
title: function x() - accès à la première coordonnée
*/
  function x() { return $this->geom['x']; }
  
/*PhpDoc: methods
name:  y
title: function y() - accès à la seconde coordonnée
*/
  function y() { return $this->geom['y']; }
  
/*PhpDoc: methods
name:  round
title: function round($nbdigits) - arrondit un point avec le nb de chiffres indiqués
*/
  function round($nbdigits) {
    return new Point([ 'x'=> round($this->geom['x'],$nbdigits),'y'=> round($this->geom['y'],$nbdigits) ]);
  }
    
/*PhpDoc: methods
name:  toString
title: function toString($nbdigits=null) - affichage des coordonnées séparées par un blanc
doc: |
  Si nbdigits est défini alors les coordonnées sont arrondies avant l'affichage.
  Si nbdigits n'est pas défini alors parent::$precision est utilisé.
  Si parent::$precision n'est pas défini alors l'ensemble des chiffres sont affichés.
*/
  function toString($nbdigits=null) {
    if ($nbdigits === null)
      $nbdigits = parent::$precision;
    if ($nbdigits === null)
      return $this->geom['x'].' '.$this->geom['y'].(isset($this->geom['z'])?' '.$this->geom['z']:'');
    else
      return round($this->geom['x'],$nbdigits).' '.round($this->geom['y'],$nbdigits)
              .(isset($this->geom['z'])?' '.$this->geom['z']:'');
  }
    
/*PhpDoc: methods
name:  __toString
title: function __toString() - affichage des coordonnées séparées par un blanc
doc: |
  Si parent::$precision est défini alors les coordonnées sont arrondies avant l'affichage
*/
  function __toString() { return $this->toString(parent::$precision); }
    
/*PhpDoc: methods
name:  wkt
title: function wkt($nbdigits=null) - retourne la chaine WKT en précisant éventuellement le nbre de chiffres significatifs
*/
  function wkt($nbdigits=null) { return 'POINT('.$this->toString($nbdigits).')'; }
  
/*PhpDoc: methods
name:  distance
title: function distance() - retourne la distance euclidienne entre 2 points
*/
  function distance(Point $pt1) { return sqrt(square($pt1->x() - $this->x()) + square($pt1->y() - $this->y())); }
  
/*PhpDoc: methods
name:  chgCoordSys
title: function chgCoordSys($src, $dest) - crée un nouveau Point en changeant le syst. de coord. de $src en $dest
doc: |
  Utilise CoordSys::chg($src, $dest, $x, $y) pour effectuer le chagt de syst. de coordonnées
*/
  function chgCoordSys($src, $dest) {
    $c = CoordSys::chg($src, $dest, $this->geom['x'], $this->geom['y']);
    return new Point(['x'=>$c[0], 'y'=>$c[1]]);
  }
  
/*PhpDoc: methods
name:  coordinates
title: function coordinates() - renvoie un tableau de coordonnées
*/
  function coordinates() { return [ (float)$this->geom['x'], (float)$this->geom['y'] ]; }
};

/*PhpDoc: classes
name:  LineString
title: Class LineString extends Geometry - Définition d'une LineString (OGC)
methods:
doc: |
  protected $geom; // Pour un LineString: [Point]
*/
class LineString extends Geometry {
/*PhpDoc: methods
name:  __construct
title: __construct($param) - construction à partir d'un WKT ou d'un [Point]
*/
  function __construct($param) {
//    echo "LineString::__construct(param=$param)\n";
    if (is_array($param)) {
      $this->geom = $param;
      return;
    }
    if (!is_string($param) or !preg_match('!^(LINESTRING\s*)?\(!', $param))
      throw new Exception("Parametre non reconnu dans LineString::__construct()");
    $this->geom = [];
      $pattern = '!^(LINESTRING\s*)?\(\s*([-\d.e]+)\s+([-\d.e]+)(\s+([-\d.e]+))?\s*,?!';
    while (preg_match($pattern, $param, $matches)) {
//      echo "matches="; print_r($matches);
//      echo "x=$matches[2], y=$matches[3]",(isset($matches[5])?",z=$matches[5]":''),"\n";
      if (isset($matches[5]))
        $this->geom[] = new Point(['x'=>$matches[2], 'y'=>$matches[3], 'z'=>$matches[5]]);
      else
        $this->geom[] = new Point(['x'=>$matches[2], 'y'=>$matches[3]]);
      $param = preg_replace($pattern, '(', $param, 1);
    }
    if ($param<>'()')
      throw new Exception("Erreur dans LineString::__construct(), Reste param=$param");
  }
    
/*PhpDoc: methods
name:  __toString
title: function __toString() - affiche la liste des points entourées par des ()
doc: |
  Si parent::$precision est défini alors les coordonnées sont arrondies avant l'affichage
*/
  function __toString() { return '('.implode(',',$this->geom).')'; }
  
/*PhpDoc: methods
name:  toString
title: function toString($nbdigits=null) - affiche la liste des points entourées par des ()
doc: |
  Si le paramètre nbdigits est défini alors les coordonnées sont arrondies avant l'affichage
*/
  function toString($nbdigits=null) {
    $str = '';
    foreach ($this->geom as $pt)
      $str .= ($str?',':'').$pt->toString($nbdigits);
    return '('.$str.')';
  }
  
/*PhpDoc: methods
name:  wkt
title: function wkt($nbdigits=null) - retourne la chaine WKT en précisant éventuellement le nbre de chiffres significatifs
*/
  function wkt($nbdigits=null) { return 'LINESTRING'.$this->toString($nbdigits); }
  
/*PhpDoc: methods
name:  bbox
title: function bbox() - calcul du rectangle englobant
*/
  function bbox() {
    $bbox = new BBox;
    foreach ($this->geom as $pt)
      $bbox->bound($pt);
    return $bbox;
  }
  
/*PhpDoc: methods
name:  filter
title: function filter($nbdigits) - filtre la géométrie en supprimant les points intermédiaires successifs identiques
*/
  function filter($nbdigits) {
//    echo "LineString::filter(nbdigits=$nbdigits)<br>\n";
//    echo "ls=$this<br>\n";
    $filter = [];
    $ptprec = null;
    foreach ($this->geom as $pt) {
//      echo "pt=$pt<br>\n";
      $rounded = $pt->round($nbdigits);
//      echo "rounded=$rounded<br>\n";
      if (!$ptprec or ($rounded<>$ptprec)) {
        $filter[] = $rounded;
//        echo "ajout de $rounded<br>\n";
      }
      $ptprec = $rounded;
    }
    return new LineString($filter);
  }
  
/*PhpDoc: methods
name:  chgCoordSys
title: function chgCoordSys($src, $dest) - créée un nouveau LineString en changeant le syst. de coord. de $src en $dest
*/
  function chgCoordSys($src, $dest) {
    $lsgeo = [];
    foreach ($this->geom as $pt)
      $lsgeo[] = $pt->chgCoordSys($src, $dest);
    return new LineString($lsgeo);
  }
  
/*PhpDoc: methods
name:  coordinates
title: function coordinates() - renvoie un tableau de coordonnées
*/
  function coordinates() {
    $coordinates = [];
    foreach ($this->geom as $pt)
      $coordinates[] = $pt->coordinates();
    return $coordinates;
  }
  
/*PhpDoc: methods
name:  draw
title: function draw(Drawing $drawing, $stroke='black', $fill='transparent', $stroke_with=2) - dessine
*/
  function draw(Drawing $drawing, $stroke='black', $fill='transparent', $stroke_with=2) {
    $drawing->drawLineString($this->geom, $stroke, $fill, $stroke_with);
  }
};

/*PhpDoc: classes
name:  Polygon
title: Class Polygon extends Geometry - Définition d'un Polygon (OGC)
methods:
doc: |
  protected $geom; // Pour un Polygon: [LineString]
*/
class Polygon extends Geometry {
/*PhpDoc: methods
name:  lineStrings
title: function lineStrings() - retourne la liste des LineStrings composant le polygone
*/
  function lineStrings() { return $geom; }
    
/*PhpDoc: methods
name:  __construct
title: __construct($param) - construction à partir d'un WKT ou d'un [LineString]
*/
  function __construct($param) {
//    echo "Polygon::__construct(param=$param)\n";
    if (is_array($param))
      $this->geom = $param;
    elseif (is_string($param) and preg_match('!^(POLYGON\s*)?\(\(!', $param)) {
      $this->geom = [];
      $pattern = '!^(POLYGON\s*)?\(\(\s*([-\d.e]+)\s+([-\d.e]+)(\s+([-\d.e]+))?\s*,?!';
      while (1) {
//        echo "boucle de Polygon::__construc sur param=$param\n";
        $pointlist = [];
        while (preg_match($pattern, $param, $matches)) {
//          echo "matches="; print_r($matches);
          if (isset($matches[5]))
            $pointlist[] = new Point(['x'=>$matches[2], 'y'=>$matches[3], 'z'=>$matches[5]]);
          else
            $pointlist[] = new Point(['x'=>$matches[2], 'y'=>$matches[3]]);
          $param = preg_replace($pattern, '((', $param, 1);
        }
        if ($param=='(())') {
          $this->geom[] = new LineString($pointlist);
          return;
        } elseif (preg_match('!^\(\(\),\(!', $param)) {
          $this->geom[] = new LineString($pointlist);
          $param = preg_replace('!^\(\(\),\(!', '((', $param, 1);
        } else
          throw new Exception("Erreur dans Polygon::__construct(), Reste param=$param");
      }
    } else
      die("Parametre non reconnu dans Polygon::__construct()");
//      throw new Exception("Parametre non reconnu dans Polygon::__construct()");
  }
  
/*PhpDoc: methods
name:  chgCoordSys
title: function chgCoordSys($src, $dest) - créée un nouveau Polygon en changeant le syst. de coord. de $src en $dest
*/
  function chgCoordSys($src, $dest) {
    $lls = [];
    foreach ($this->geom as $ls)
      $lls[] = $ls->chgCoordSys($src, $dest);
    return new Polygon($lls);
  }
    
/*PhpDoc: methods
name:  __toString
title: function __toString() - affiche la liste des LineString entourée par des ()
*/
  function __toString() { return '('.implode(',',$this->geom).')'; }
  
/*PhpDoc: methods
name:  toString
title: function toString($nbdigits=null) - affiche la liste des LineString entourée par des () en précisant éventuellement le nbre de chiffres significatifs
*/
  function toString($nbdigits=null) {
    $str = '';
    foreach ($this->geom as $ls)
      $str .= ($str?',':'').$ls->toString($nbdigits);
    return '('.$str.')';
  }
  
/*PhpDoc: methods
name:  wkt
title: function wkt($nbdigits=null) - retourne la chaine WKT en précisant éventuellement le nbre de chiffres significatifs
*/
  function wkt($nbdigits=null) { return 'POLYGON'.$this->toString($nbdigits); }
  
/*PhpDoc: methods
name:  bbox
title: function bbox() - calcul du rectangle englobant
*/
  function bbox() { return $this->geom[0]->bbox(); }
  
/*PhpDoc: methods
name:  filter
title: function filter($nbdigits) - filtre la géométrie en supprimant les points intermédiaires successifs identiques
*/
  function filter($nbdigits) {
    $result = [];
    foreach ($this->geom as $ls) {
//      echo "ls=$ls<br>\n";
      $filtered = $ls->filter($nbdigits);
//      echo "filtered polygon=$filtered<br>\n";      
      $result[] = $filtered;
    }
    return new Polygon($result);
  }
  
/*PhpDoc: methods
name:  coordinates
title: function coordinates() - renvoie un tableau de coordonnées
*/
  function coordinates() {
    $coordinates = [];
    foreach ($this->geom as $ls)
      $coordinates[] = $ls->coordinates();
    return $coordinates;
  }
  
/*PhpDoc: methods
name:  draw
title: function draw(Drawing $drawing, $stroke='black', $fill='transparent', $stroke_with=2) - dessine
*/
  function draw(Drawing $drawing, $stroke='black', $fill='transparent', $stroke_with=2) {
    $drawing->drawPolygon($this->geom, $stroke, $fill, $stroke_with);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

echo "<html><head><meta charset='UTF-8'><title>geom2d</title></head><body><pre>";

//Geometry::setParam('precision', -2);

$pt = new Point('POINT(15 20)');
$pt2 = new Point('POINT(15 20)');
$pt3 = new Point('POINT(-e-5 15 20)');
//print_r($pt);
echo "pt=$pt\n";
echo "pt3=$pt3\n";
echo ($pt2==$pt ? "pt2==pt" : 'pt2<>pt'),"\n";

$pt = new Point('POINT(15 20 99)');
$pt = Geometry::create('POINT(15 20 99)');
echo "pt=$pt\n";
echo "coordinates="; print_r($pt->coordinates());

$ls = new LineString('LINESTRING(-e-5 0, 10 10, 20 25 999, 50 60)');
$ls = Geometry::create('LINESTRING(0 -e-5, 10 10, 20 25 999, 50 60)');
//print_r($ls);
echo "ls=$ls\n";
echo "ls=",$ls->wkt(),"\n";
echo "bound(ls)=",$ls->bbox(),"\n";
echo "coordinates="; print_r($ls->coordinates());

$polygon = new Polygon('POLYGON((0 0,10 0,10 10,0 10,0 0),(5 5,7 5,7 7,5 7, 5 5))');
echo "polygon=$polygon\n";
//die("OK ligne ".__LINE__);

//$polygon = Geometry::create('POLYGON((0 0,10 0,10 10,0 10,0 0),(5 5,7 5,7 7,5 7, 5 5))');
$polygon = Geometry::create('POLYGON((0 -e-6,10 0 9,10 -10 777,0 10,0 0),(5 5,7 5,7 7,5 7, 5 5))');
echo "polygon=$polygon\n";
//print_r($polygon);
echo "bound(polygon)=",$polygon->bbox(),"\n";
echo "coordinates="; print_r($polygon->coordinates());
