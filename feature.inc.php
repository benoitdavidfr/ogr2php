<?php
/*PhpDoc:
name:  feature.inc.php
title: feature.inc.php - objet géographique
classes:
doc: |
  Gestion d'un objet géographique composé d'une liste de champs et d'une géométrie
journal: |
  31/7/2018:
  - première version
*/
require_once __DIR__.'/../geometry/inc.php';
/*PhpDoc: classes
name:  Feature
title: class Feature - Définition d'un objet géographique composé d'une liste de champs et d'une géométrie
methods:
doc: |
*/
class Feature {
  private $properties; // dictionnaire des champs
  //private $fieldslc; // dictionnaire des champs avec noms des champs en minuscules
  private $geometry; // objet Geometry
  
/*PhpDoc: methods
name:  __construct
title: function __construct(array $$properties, Geometry $geometry)
*/
  function __construct(array $properties, Geometry $geometry) {
    $this->properties = $properties;
    /*$this->fieldslc = [];
    foreach ($fields as $key => $value)
      $this->fieldslc[strtolower($key)] = $value;
    */
    $this->geometry = $geometry;
  }

/*PhpDoc: methods
name:  fields
title: function properties() { return $this->properties; }
*/
  function properties() { return $this->properties; }
  
/*PhpDoc: methods
name:  fields
title: function field($key) - recherche un champ independamment de la casse, key peut être une liste de champs
  function field($name) {
    if (is_string($name))
      return (isset($this->fieldslc[strtolower($name)]) ? $this->fieldslc[strtolower($name)] : null);
    else
      foreach ($name as $n)
        if (isset($this->fieldslc[strtolower($n)]))
          return $this->fieldslc[strtolower($n)];
    return null;
  }
*/
  
/*PhpDoc: methods
name:  wkt
title: function geometry() - recherche la géométrie
*/
  function geometry() { return $this->geometry; }
  
  /*PhpDoc: methods
  name:  __toString
  title: function __toString() - représentation GeoJSON
  */
  function __toString() {
    $feature = [
      'type'=> 'Feature',
      'geometry'=> $this->geometry->geojson(),
      'properties'=> $this->properties,
    ];
    return json_encode($feature);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>feature</title></head><body><pre>\n";

$geometry = [
  'type'=>'Polygon',
  'coordinates'=>[
    [[5, 4],[15, 14],[15, 18],[5, 4]],
    [[7, 8],[10, 12],[9, 8],[5, 4]],
  ],
];
$geometry = Geometry::fromGeoJSON($geometry);
$feature = new Feature(['prop'=>'value'], $geometry);
echo "feature=$feature\n";