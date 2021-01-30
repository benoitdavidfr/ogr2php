<?php
/*PhpDoc:
name:  feature.inc.php
title: feature.inc.php - objet géographique
includes: [ '../gegeom/gegeom.inc.php' ]
classes:
doc: |
  Gestion d'un objet géographique composé d'une liste de champs et d'une géométrie
journal: |
  6/5/2019:
    - migration de geometry sur gegeom
  8/8/2018:
    - un feature peut avoir une géometry null car cela se rencontre
  1/8/2018:
    - première version
*/
require_once __DIR__.'/../gegeom/gegeom.inc.php';
use \gegeom\Geometry;

/*PhpDoc: classes
name:  Feature
title: class Feature - Définition d'un objet géographique composé d'une liste de champs et d'une géométrie
methods:
doc: |
*/
class Feature {
  public $id=null; // éventuel id
  public array $properties; // dictionnaire des champs
  public ?Geometry $geometry=null; // objet Geometry ou null
  
  /*PhpDoc: methods
  name:  __construct
  title: function __construct(string $param)
  doc: |
    $param est un GeoJSON sous la forme d'un string
  */
  function __construct(string $param) {
    $feature = json_decode($param, true);
    if ($feature['type']<>'Feature') {
      throw new Exception("GeoJSON '$param' not a feature");
    }
    if (isset($feature['id']))
      $this->id = $feature['id'];
    $this->properties = $feature['properties'];
    if (isset($feature['geometry']))
      $this->geometry = Geometry::fromGeoJSON($feature['geometry']);
    {/* Ancienne version < 28/1/2021
    function __construct($param) {
      if (is_string($param)) {
        $feature = json_decode($param, true);
        if ($feature['type']<>'Feature') {
          print_r($feature);
          throw new Exception("GeoJSON '$feature' not a feature");
        }
        $this->properties = $feature['properties'];
        if (!$feature['geometry'])
          $this->geometry = null;
        else
          $this->geometry = Geometry::fromGeoJSON($feature['geometry']);
      }
      elseif (is_array($param)) {
        if (!is_array($param['properties']))
          throw new Exception("dans Feature::__construct(), param[properties] doit être un array");
        $this->properties = $param['properties'];
        if (!is_object($param['geometry']) || !is_subclass_of($param['geometry'], 'Geometry')) {
          //echo "param[geometry]=$param[geometry]<br>\n";
          //print_r($param['geometry']);
          throw new Exception(
            "Erreur dans Feature::__construct(), param[geometry] doit être un objet d'une sous-classe de Geometry");
        }
        $this->geometry = $param['geometry'];
      }
    }*/
    }
  }

  /*PhpDoc: methods
  name:  properties
  title: "function properties(): array { return $this->properties; }"
  */
  //function properties(): array { return $this->properties; }
  
  /*PhpDoc: methods
  name:  properties
  title: "function property(string $name) { return $this->properties[$name]; }"
  */
  //function property(string $name) { return $this->properties[$name]; }
  
  /*PhpDoc: methods
  name:  wkt
  title: "function geometry(): ?Geometry { return $this->geometry; } - retourne la géométrie"
  */
  //function geometry(): ?Geometry { return $this->geometry; }
  
  /*PhpDoc: methods
  name:  geojson
  title: "function geojson(): array - retourne un tableau Php qui encodé en JSON correspondra à la geometry GeoJSON"
  */
  function geojson(): array {
    return [
      'type'=> 'Feature',
    ]
    + ($this->id ? ['id'=> $this->id] : [])
    + [
      'properties'=> $this->properties,
      'geometry'=> $this->geometry ? $this->geometry->asArray() : null,
    ];
  }
  
  /*PhpDoc: methods
  name:  __toString
  title: function __toString() - représentation GeoJSON
  */
  function __toString(): string { return json_encode($this->geojson()); }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>feature</title></head><body><pre>\n";
/*
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
*/
$geojson = '{ "type": "Feature", "properties": { "ID_RTE500": 5890, "NATURE": "Voie normale", "ENERGIE": "Electrifiée", "CLASSEMENT": "En service" }, "geometry": { "type": "LineString", "coordinates": [ [ 6.48, 47.88 ], [ 6.61, 47.217 ] ] } }';
$feature = new Feature($geojson);
echo "feature=$feature\n";

$feature = new Feature('xxx');
echo "feature=$feature\n";

