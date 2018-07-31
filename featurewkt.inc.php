<?php
/*PhpDoc:
name:  featurewkt.inc.php
title: featurewkt.inc.php - objet géographique
classes:
doc: |
  Gestion d'un objet géographique composé d'une liste de champs et d'un WKT (abandonné)
journal: |
  31/7/2018:
  - remplacement par feature
  17/7/2016:
  - duplication des champs pour faciliter les recherches indépendamment de la casse
  16/7/2016:
  - fork de feature.inc.php
  - le WKT est stocké telquel et restitué pour améliorer les performances, notamment diminuer l'espace mémoire nécessaire
  11/6/2016:
  - création de la classe GeomCollection
  7/6/2016:
  - ajout de la détection de GEOMETRYCOLLECTION()
  6/6/2016:
  - calcul du bbox pour une MultiGeometry
  1/6/2016:
  - première version
  - il pourra être utile de définir une classe MultiGeometry
*/

/*PhpDoc: classes
name:  Feature
title: class Feature - Définition d'un objet géographique composé d'une liste de champs et d'un WKT
methods:
doc: |
*/
class Feature {
  private $fields; // liste des champs
  private $fieldslc; // liste des champs avec noms des champs en minuscules
  private $wkt; // WKT
  
/*PhpDoc: methods
name:  __construct
title: function __construct($fields, $wkt)
*/
  function __construct($fields, $wkt) {
    $this->fields = $fields;
    $this->fieldslc = [];
    foreach ($fields as $key => $value)
      $this->fieldslc[strtolower($key)] = $value;
    $this->wkt = $wkt;
  }

/*PhpDoc: methods
name:  fields
title: function fields() { return $this->fields; }
*/
  function fields() { return $this->fields; }
  
/*PhpDoc: methods
name:  fields
title: function field($key) - recherche un champ independamment de la casse, key peut être une liste de champs
*/
  function field($key) {
    if (is_string($key))
      return (isset($this->fieldslc[strtolower($key)]) ? $this->fieldslc[strtolower($key)] : null);
    else
      foreach ($key as $k)
        if (isset($this->fieldslc[strtolower($k)]))
          return $this->fieldslc[strtolower($k)];
    return null;
  }
  
/*PhpDoc: methods
name:  wkt
title: function wkt() - recherche le wkt
*/
  function wkt() { return $this->wkt; }
  
  function __toString() {
    $fields = $this->fields;
    $fields['geometry'] = $this->wkt();
    return json_encode($fields);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo <<<EOT
<!DOCTYPE HTML PUBLIC "-//W1C//DTD HTML 1 Transitional//EN" "http://www.w1.org/TR/html1/loose.dtd">
<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-1"><title>feature</title></head><body><pre>\n
EOT;

// Test de prise en compte d'un MULTIPOLYGON
$geomstr = <<<EOT
EOT;

$feature = new Feature([], $geomstr);
echo "feature=$feature\n";
