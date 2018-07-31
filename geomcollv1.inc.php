<?php
/*PhpDoc:
name:  geomcoll.inc.php
title: geomcoll.inc.php - collection de géométries
includes: [ 'geom2d.inc.php' ]
classes:
doc: |
  Un objet GeoCollection est un ensemble d'objets d'une des sous-classes de Geometry
journal: |
  5/12/2016:
  - ajout d'une méthode coordinates()
  12/11/2016:
  - ajout d'une méthode filter() pour éviter d'avoir 2 points identiques
  25/6/2016:
  - ajout de la méthode collection()
  11/6/2016:
  - première version
*/
require_once 'geom2d.inc.php';

/*PhpDoc: classes
name:  GeomCollection
title: Class GeomCollection - Liste d'objets d'une des sous-classes de Geometry
methods:
*/
class GeomCollection {
  protected $collection; // liste d'objets d'une des sous-classes de Geometry
  
/*PhpDoc: methods
name:  create
title: static function create($wkt) - teste si le WKT correspond à un GeomCollection et si c'est le cas crée l'objet
*/
  static function create($wkt) {
    if ((strncmp($wkt,'MULTIPOLYGON',strlen('MULTIPOLYGON'))==0)
     or (strncmp($wkt,'MULTILINESTRING',strlen('MULTILINESTRING'))==0)
     or (strncmp($wkt,'GEOMETRYCOLLECTION',strlen('GEOMETRYCOLLECTION'))==0))
      return new GeomCollection($wkt);
    else
      return null;
  }
  
/*PhpDoc: methods
name:  collection
title: function collection() { return $this->collection; }
*/
  function collection() { return $this->collection; }
  
/*PhpDoc: methods
name:  __construct
title: function __construct($geomstr) - initialise un GeomCollection à partir d'un WKT ou d'une liste de Geometry
*/
  function __construct($geomstr) {
    if (is_array($geomstr)) {
      $this->collection = $geomstr;
      return;
    }
    $this->collection = [];
    if (strncmp($geomstr,'MULTIPOLYGON',strlen('MULTIPOLYGON'))==0) {
      $ring = '\([-0-9. ,]+\),?';
      $pattern = "!^MULTIPOLYGON\s*\((\(($ring)*\)),?!";
      while (preg_match($pattern, $geomstr, $matches)) {
 //       echo "matches="; print_r($matches);
        $this->collection[] = Geometry::create("POLYGON $matches[1]");
        $geomstr = preg_replace($pattern, 'MULTIPOLYGON(', $geomstr, 1);
      }
      if ($geomstr<>'MULTIPOLYGON()')
        die("Erreur de lecture ligne ".__LINE__." sur $geomstr");
      
    } elseif (strncmp($geomstr,'MULTILINESTRING',strlen('MULTILINESTRING'))==0) {
      $lspattern = '\([-0-9. ,]+\)';
      $pattern = "!^MULTILINESTRING\s*\(($lspattern),?!";
      while (preg_match($pattern, $geomstr, $matches)) {
//        echo "matches="; print_r($matches);
        $this->collection[] = Geometry::create("LINESTRING $matches[1]");
        $geomstr = preg_replace($pattern, 'MULTILINESTRING(', $geomstr, 1);
      }
      if ($geomstr<>'MULTILINESTRING()')
        die("Erreur de lecture ligne ".__LINE__." sur $geomstr");
      
    } elseif (strncmp($geomstr,'GEOMETRYCOLLECTION',strlen('GEOMETRYCOLLECTION'))==0) {
      $ring = '\([-0-9. ,]+\),?';
      $pattern = "!^GEOMETRYCOLLECTION\((POLYGON\s*\(($ring)*\)|LINESTRING\s*\([-0-9.e ,]+\)),?!";
      while (preg_match($pattern, $geomstr, $matches)) {
//        echo "matches="; print_r($matches);
        $this->collection[] = Geometry::create($matches[1]);
        $geomstr = preg_replace($pattern, 'GEOMETRYCOLLECTION(', $geomstr, 1);
      }
      if ($geomstr<>'GEOMETRYCOLLECTION()')
        die("Erreur de lecture ligne ".__LINE__." sur $geomstr");
    }
  }
  
/*PhpDoc: methods
name:  bbox
title: function bbox() - calcule le bbox
*/
  function bbox() {
    $bbox = new BBox;
    foreach ($this->collection as $geom)
      $bbox->union($geom->bbox());
    return $bbox;
  }
  
/*PhpDoc: methods
name:  filter
title: function filter($nbdigits) - filtre la géométrie en supprimant les points intermédiaires successifs identiques
*/
  function filter($nbdigits) {
    $collection = [];
    foreach ($this->collection as $geom) {
//      echo "geom=$geom<br>\n";
      $filtered = $geom->filter($nbdigits);
//      echo "filtered=$filtered<br>\n";
      $collection[] = $filtered;
    }
    return new GeomCollection($collection);
  }
  
/*PhpDoc: methods
name:  __toString
title: function __toString() - génère une chaine de caractère correspondant au WKT sans l'entete
*/
  function __toString() {
    $str = '';
    foreach($this->collection as $geom)
      $str .= ($str?',':'').$geom->wkt();
    return '('.$str.')';
  }
  
/*PhpDoc: methods
name:  chgCoordSys
title: function chgCoordSys($src, $dest) - créée un nouveau GeomCollection en changeant le syst. de coord. de $src en $dest
*/
  function chgCoordSys($src, $dest) {
    $collection = [];
    foreach($this->collection as $geom)
      $collection[] = $geom->chgCoordSys($src, $dest);
    return new GeomCollection($collection);
  }
    
/*PhpDoc: methods
name:  wkt
title: function wkt() - génère une chaine de caractère correspondant au WKT avec l'entete
*/
  function wkt() { return 'GEOMETRYCOLLECTION'.$this; }
  
/*PhpDoc: methods
name:  geojsonGeometry
title: function coordinates() - renvoie un tableau de coordonnées en GeoJSON
*/
  function coordinates() {
    $coordinates = [];
    foreach ($this->collection as $geom)
      $coordinates[] = $geom->coordinates();
    return $coordinates;
  }
  
/*PhpDoc: methods
name:  draw
title: function draw() - itère l'appel de draw sur chaque élément
*/
  function draw(Drawing $drawing, $stroke='black', $fill='transparent', $stroke_with=2) {
    foreach($this->collection as $geom)
      $geom->draw($drawing, $stroke, $fill, $stroke_with);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo <<<EOT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><title>geomcoll</title></head><body><pre>\n
EOT;
/*
// Test de prise en compte d'un MULTIPOLYGON
$geomstr = <<<EOT
MULTIPOLYGON (((153042 6799129,153043 6799174,153063 6799199),(1 1,2 2)),((154613 6803109.5,154568 6803119,154538.89999999999 6803145)))
EOT;

$geomcoll = GeomCollection::create($geomstr);
echo "geomcoll=$geomcoll\n";
*/
$geomstr = 'GEOMETRYCOLLECTION(POLYGON((0.037489127175144 46.892802988331,0.037559678217379 46.892714703402,0.037961900853977 46.892497999501,0.039516343691303 46.891448669036,0.036405662035267 46.89053160555,0.033868814195428 46.889985137941,0.030416690624582 46.889353739284,0.027283159756188 46.88895301111,0.025220494362667 46.884995656424,0.023635781125412 46.880644255777,0.025620352647466 46.880279287748,0.030570105821681 46.879613296212,0.035524616444886 46.878858017757,0.041452109610085 46.876862532029,0.041466075464833 46.874972581652,0.041944784378243 46.874531680224,0.043912759302991 46.872996474257,0.050135780878241 46.869779184882,0.054203403784444 46.86555135768,0.053680828397772 46.860815820043,0.053577661384433 46.858608420654,0.0489044653983 46.85896528779,0.04217786147561 46.858245652145,0.037828668447321 46.857168781026,0.036991575649187 46.856792227829,0.034750224683406 46.855531631775,0.03359850416433 46.854833390215,0.033762378878905 46.854071753325,0.025614185916894 46.852872967775,0.014937604936094 46.857067312536,0.0096357998198415 46.859074973719,0.0033943277746544 46.861558000916,0.0021246474645147 46.862116901754,-0.00028505848129763 46.863192992575,-0.00095578742608961 46.863538615779,-0.0029260519284199 46.865073924219,-0.0037452704453649 46.865822020365,-0.0045642172638386 46.868055912475,-0.0045912553699758 46.868685024543,-0.01054001257356 46.871164164897,-0.015861375676029 46.872819691909,-0.016856766324364 46.873069319086,-0.020757818795665 46.87375359462,-0.024504593200149 46.874261587993,-0.027223163254313 46.874520793568,-0.035440392494865 46.874845887223,-0.035640979048293 46.87493160169,-0.033857259576336 46.876138271094,-0.026847413549739 46.883731135937,-0.023402780029602 46.890665889182,-0.018211104303499 46.89806258486,-0.016791610821991 46.898631389763,-0.014170203393691 46.901363466034,-0.011962866737355 46.903816139694,-0.0090362987061272 46.907476003456,-0.0089853768922112 46.907836840505,-0.010478918453567 46.911947000055,-0.010618792989395 46.91216859842,-0.013866550904742 46.921867816701,-0.0134761514939 46.921965428889,-0.011736344171489 46.922721830872,-0.004746360740156 46.929320475505,-0.0035032919065016 46.935805020234,0.0027540895593312 46.936968075165,0.026366372227694 46.940955873149,0.027188730051491 46.93863270282,0.027024403442871 46.936334529208,0.026817582871411 46.935024550745,0.02659981819921 46.933985488656,0.02604751320817 46.931543948567,0.024786071214396 46.925713454765,0.024516059347982 46.924358585523,0.023819274639509 46.920698741289,0.022779675768065 46.915863227417,0.021901953717158 46.911840079422,0.021414620030789 46.908680266444,0.022088349459509 46.905971246524,0.023807653532412 46.904160522575,0.026886042924333 46.901274834864,0.037156139864566 46.892931372108)),POLYGON((0.014906473844852 46.834357085163,0.01078587332198 46.833202159958,0.0088979653271416 46.832202486225,0.0015090166468517 46.825609118386,-0.0043015722310168 46.819959313067,-0.0049859027667197 46.820133350695,-0.010408800078029 46.818654343063,-0.014675506779993 46.815555582447,-0.019930301844643 46.812792015495,-0.024806695434027 46.814896610459,-0.026412314020494 46.817113284848,-0.027828045926594 46.818771783914,-0.029409496813954 46.819706862893,-0.030410212700102 46.820091369734,-0.031269858833018 46.820253423832,-0.036521494634514 46.820437421933,-0.043579946911908 46.820841880314,-0.048047106613855 46.821615587115,-0.049093065813708 46.82303405191,-0.045725450721217 46.823958586272,-0.045685445098635 46.826772742279,-0.045689183457 46.830575095495,-0.04569118485732 46.832105032832,-0.042161865090607 46.832358838312,-0.037362953283748 46.832098235166,-0.035459012099899 46.832047588792,-0.034476788697427 46.832068104573,-0.033347639554634 46.833216095172,-0.032099723699869 46.834682571102,-0.030919811648963 46.836192291319,-0.02840826642881 46.839484194766,-0.024098544835819 46.842138530857,-0.013606145467318 46.845931562763,-0.0077688625346321 46.847469231476,0.00058777867426029 46.846064672081,0.0066262775259956 46.842947629657,0.011516509830432 46.839829289177,0.014222941358809 46.835586363248,0.014580092702376 46.834918963433,0.014906473844852 46.834357085163)))';
$col = new GeomCollection($geomstr);
echo "col=$col<br>\n";
$filtered = $col->filter(2);
echo "col filtered=$filtered<br>\n";
