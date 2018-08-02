<?php
/*PhpDoc:
name:  feature.inc.php
title: feature.inc.php - objet géographique
classes:
doc: |
  Gestion d'un objet géographique composé d'une liste de champs et d'une géométrie
journal: |
  1/8/2018:
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
  public $properties; // dictionnaire des champs
  public $geometry; // objet Geometry
  
  /*PhpDoc: methods
  name:  __construct
  title: function __construct($param)
  doc: |
    $param peut être:
      - un GeoJSON sous la forme d'un string
      - un array avec un élément properties et un autre geometry qui doit être un Geometry
  */
  function __construct($param) {
    if (is_string($param)) {
      $feature = json_decode($param, true);
      if ($feature['type']<>'Feature')
        throw new Exception("GeoJSON '$geojson' not a feature");
      $this->properties = $feature['properties'];
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
  }

  /*PhpDoc: methods
  name:  properties
  title: function properties() { return $this->properties; }
  */
  function properties() { return $this->properties; }
  
  /*PhpDoc: methods
  name:  properties
  title: function property()
  */
  function property(string $name) { return $this->properties[$name]; }
  
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
  
  /*PhpDoc: methods
  name:  geojson
  title: "function geojson(): array - retourne un tableau Php qui encodé en JSON correspondra à la geometry GeoJSON"
  */
  function geojson(): array {
    return [
      'type'=> 'Feature',
      'properties'=> $this->properties,
      'geometry'=> $this->geometry->geojson(),
    ];
  }
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

