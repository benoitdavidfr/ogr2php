<?php
/*PhpDoc:
name: sqlloader.php
title: sqlloader.php - module générique de chargement d'un produit dans une base MySQL
includes: [ ogr2php.inc.php ]
classes:
doc: |
  La classe SqlLoader met en oeuvre un chargeur SQL en fonction de paramètres du produit à charger
  Utilisation systématique du type Geometry
  L'exécution du script effectue le chargement de qqs produits définis dans YamlDoc
  
journal: |
  13/8/2018
    - intégration du nom de la base comme paramètre
    - ajout possibilité de charger plusieurs couches SHP dans une seule table
  9/8/2018
    chgt du nom de table qui est le lyrname
  8/8/2018
    ajout de la base dans create_table et insert_into
    ajout du test de validité d'une géométrie dans insert_into
  31/7-2/8/2018
    passage en V2
  24/12/2016
    ajout d'un filtre sur la géométrie dans insert_into()
  22/12/2016
    modification du nom des colonnes des tables lorsqu'il correspond à un mot-clé SQL
  19/12/2016
    chgt de l'interface de sqlloader pour la définition des datasets
    chgt du type de géométrie à Geometry
  18/12/2016
    correction d'un bug lorsqu'il n'y a qu'un seul jeu
  17/12/2016
    sqlloader renvoie ogrinfo pour les actions info, create_table et load_table
  10/12/2016
    correction d'un bug
  9/12/2016
    ajout temporaire de la commande missing très spécifique à geoapi.fr/bdv
    il faut améliorer sa fiabilité et sa généricité
  7-8/12/2016
    adaptation à geoapi.fr/bdv
    Je constate sur geoapi.alwaysdata.net que MySQL modifie le type géométrique défini pour le remplacer par geometry
    Il est donc plus simple d'utiliser systématiquement le type Geometry plutot que MultiPolygon
  4-6/12/2016
    première version
*/
require_once __DIR__.'/../phplib/mysql.inc.php';
require_once __DIR__.'/ogr2php.inc.php';

/*PhpDoc: classes
name:  Feature
title: class Feature - Définition d'un objet géographique composé d'une liste de champs et d'une géométrie
methods:
doc: |
*/
class SqlLoader {
  static $sql_reserved_words = ['add','ignore'];
  // les chemins possibles pour le répertoire des données
  static $dataStorePaths = [
    '/home/bdavid/www/data',
    '/var/www/html/data',
  ];
  
  static function dataStorePath() {
    foreach (self::$dataStorePaths as $dataStorePath)
      if (is_dir($dataStorePath))
        return $dataStorePath;
  }
  
  // affiche la liste des fichiers SHP absent de $shpPaths
  static function shpFiles(string $dbpath, array $shpPaths): void {
    $dirpath = SqlLoader::dataStorePath().'/'.$dbpath;
    $dir = dir($dirpath);
    while (false !== ($entry = $dir->read())) {
      $ext = substr($entry, strrpos($entry, '.')+1);
      if (in_array(strtoupper($ext), ['SHP']) && !in_array($entry, $shpPaths))
        echo $entry."\n";
    }
    $dir->close();
  }
  
  // transformation du type Ogr en type SQL
  static function sqltype(string $fieldtype): string {
    if ($fieldtype=='Integer')
      return 'integer';
    if ($fieldtype=='Integer64')
      return 'bigint';
    if (preg_match('!^String\((\d+)\)$!', $fieldtype, $matches))
      return "varchar($matches[1])";
    if (preg_match('!^Real\((\d+)\.(\d+)\)$!', $fieldtype, $matches))
      return "decimal($matches[1],$matches[2])";
    throw new Exception("dans sqltype(), type '$fieldtype' inconnu");
  }
  
  /*PhpDoc: methods
  name: create_table
  title: "static function OgrInfo $ogr, array $tableDef, string $mysql_database): array - instructions SQL create table"
  doc: |
    $info correspond à $ogr->info()
    $tableDef correspond à la définition des paramètres pour une table
  */
  static function create_table(OgrInfo $ogr, array $tableDef, string $mysql_database): array {
    //print_r($tableDef);
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
    $table_name = $tableDef['_id'];
    $sqls[0] = "drop table if exists $mysql_database$table_name";
    $sql = "create table $mysql_database$table_name (\n";
    $sqlfields = [];
    if (isset($info['fields']))
      foreach ($info['fields'] as $field) {
        if (isset($tableDef['excludedFields']) && in_array($field['name'], $tableDef['excludedFields']))
          continue;
  //    print_r($field);
        $name = strtolower($field['name']);
  // cas d'utilisation d'un mot-clé SQL comme nom de champ
        if (in_array($name, self::$sql_reserved_words))
          $name = "col_$name";
        $sqlfields[] = "  $name ".self::sqltype($field['type']).' not null';
      }
    $sql .= implode(",\n",$sqlfields).",\n";
    $sql .= "  geom Geometry not null\n";
    $sql .= ")\n"
         ."ENGINE = MYISAM\n"
         ."DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
    $sqls[] = $sql;
    $sqls[] = "create spatial index ${table_name}_geom on $mysql_database$table_name(geom)";
    if (isset($tableDef['indexes']) and $tableDef['indexes']) {
      foreach ($tableDef['indexes'] as $index_fields=>$unique) {
        $index_name = str_replace(',','_',$index_fields);
        $sqls[] = "create ".($unique ? 'unique ':'')
          ."index ${table_name}_${index_name} on $mysql_database$table_name($index_fields);\n";
      }
    }
    return $sqls;
  }
  
  static function insert_into(Ogr2Php $ogr, array $tableDef, string $mysql_database, int $precision, int $nbrmax=20): array {
    $transaction = true; // utilisation des transactions
    //$transaction = false; // utilisation des transactions
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
    $table_name = $tableDef['_id'];
    $fields = [];
    foreach ($info['fields'] as $field) {
      if (isset($tableDef['excludedFields']) && in_array($field['name'], $tableDef['excludedFields']))
        continue;
      $name = strtolower($field['name']);
      if (in_array($name, self::$sql_reserved_words))
        $name = "col_$name";
      $fields[$field['name']] = $name;
    }
    //Geometry::setParam('precision', $precision);
    $sqls = [];
    //$sqls = ["truncate $mysql_database$table_name"];
    if ($transaction)
      $sqls[] = "start transaction";
    $nbre = 0;
    foreach ($ogr as $feature) {
      $nbre++;
      if ($transaction && ($nbre % 1000 == 0))
        $sqls[] = "commit";
      if ($nbrmax && ($nbre > $nbrmax)) {
        if ($transaction)
          $sqls[] .= "commit";
        $sqls[] = "-- Arrêt après $nbrmax\n";
        return $sqls;
      }
      //echo "feature=$feature\n";
      $sql = "insert into $mysql_database$table_name(".implode(',',$fields).",geom) values\n";
      $values = [];
      foreach ($fields as $propname => $field)
        $values[] = '"'.str_replace('"','""',$feature->property($propname)).'"';
      if (!$feature->geometry()) {
        echo "geométrie vide pour :",implode(',',$values),"\n";
        continue;
      }
      $sql .= "(".implode(',',$values);
      $geom0 = $feature->geometry()->proj2D();
      $geom = $geom0->filter($precision);
      if (!$geom->isValid()) {
        //echo "geometry non filtré=$geom0\n";
        echo "geometry=",$geom->wkt(),"\n";
        //throw new Exception("geometry invalide ligne ".__LINE__);
        echo "geometry invalide pour :",implode(',',$values),"\n";
        continue;
      }
      $wkt = $geom->wkt();
      $sql .= ",ST_GeomFromText('$wkt'))";
      if ($geom->isValid())
        $sqls[] = $sql;
      else
        echo "-- invalid $sql\n";
    }
    if ($transaction)
      $sqls[] = "commit";
    return $sqls;
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

if (php_sapi_name()<>'cli') {
  ini_set('max_execution_time', 600);
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>sqlooader2</title></head><body><pre>\n";
}
ini_set('memory_limit', '1280M');

require_once __DIR__.'/../yamldoc/inc.php';

if (php_sapi_name() == 'cli') {
  if ($argc <= 1) {
    //echo "argc=$argc\n";
    //print_r($argv);
    echo "usage: $argv[0] <doc> [<cmde> [<layer>]]\n";
    echo "où <doc> vaut:\n";
    echo "  route500 - pour Route500\n";
    echo "  ne_110m - pour Natural Earth 110m\n";
    echo "  ne_10m - pour Natural Earth 110m\n";
    die();
  }
  elseif ($argc == 2) {
    echo "usage: $argv[0] $argv[1] [<cmde> [<layer>]]\n";
    $docid = "geodata/$argv[1]";
    echo "doc est $docid\n";
    Store::setStoreid('pub'); // le store dans lequel est le doc
    if (!($geodataDoc = new_doc($docid)))
      die("$docid inexistant dans le store\n");
    echo "où <cmde> vaut:\n";
    echo "  yaml - affiche le document\n";
    echo "  shp - affiche la liste des fichiers SHP\n";
    echo "  missing - affiche la liste des fichiers SHP absent du document\n";
    echo "  ogrinfo <layer> - effectue un ogrinfo sur la couche\n";
    echo "  create_table <layer> - génère les ordres SQL pour créer la table correspondant à la couche\n";
    echo "  insert_into <layer> - génère les orders SQL pour peupler la table correspondant à la couche\n";
    echo "  load <layer> - crée la table pour la couche et la peuple\n";
    echo "  loadall - génère les ordres sh pour créer ttes les tables et les peupler\n";
    echo "où <layer> vaut:\n";
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['path']))
        echo "  $lyrname pour $layer[title]\n";
    }
    die();
  }
  elseif (($argc == 3) && !in_array($argv[2], ['yaml','shp','missing','loadall'])) {
    Store::setStoreid('pub'); // le store dans lequel est le doc
    if (!($geodataDoc = new_doc("geodata/$argv[1]")))
      die("$argv[1] inexistant dans le store pub\n");
    echo "usage: $argv[0] $argv[1] $argv[2] <layer>\n";
    echo "où <layer> vaut:\n";
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['path']))
        echo "  $lyrname pour $layer[title]\n";
    }
    die();
  }
  else {
    Store::setStoreid('pub'); // le store dans lequel est le doc
    if (!($geodataDoc = new_doc("geodata/$argv[1]")))
      die("$argv[1] inexistant dans le store pub\n");
    $action = $argv[2];
    $lyrname = isset($argv[3]) ? $argv[3] : null;
  }
}
else { // php_sapi_name() != 'cli'
  if (!isset($_GET['action'])) {
    echo "</pre><h3>Actions possibles:</h3>\n";
    foreach(['yaml','ogrinfo','create_table','insert_into'] as $action)
      echo "<a href='?action=$action'>$action<br>";
    die();
  }
  elseif (($_GET['action'] <> 'yaml') && !isset($_GET['layer'])) {
    echo "</pre><h3>$_GET[action] sur quelle couche ?</h3>\n";
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['path']))
        echo "<a href='?action=$_GET[action]&amp;layer=$lyrname'>$layer[title]</a><br>\n";
    }
    die();
  }
  $action = $_GET['action'];
  $lyrname = isset($_GET['layer']) ? $_GET['layer'] : null;
}

if ($lyrname) {
  if (!isset($geodataDoc->asArray()['layers'][$lyrname]))
    die("Erreur: layer $lyrname inconnue\n");
  $tableDef = $geodataDoc->asArray()['layers'][$lyrname];
  $tableDef['_id'] = $lyrname;
  if (is_string($tableDef['path']))
    $lyrpaths = [ SqlLoader::dataStorePath().'/'.$geodataDoc->asArray()['dbpath'].'/'.$tableDef['path'] ];
  else {
    $lyrpaths = [];
    foreach ($tableDef['path'] as $path)
      $lyrpaths[] = SqlLoader::dataStorePath().'/'.$geodataDoc->asArray()['dbpath'].'/'.$path;
  }
}

if (!file_exists(__DIR__.'/mysqlparams.inc.php')) {
  die("Cette commande n'est pas disponible car l'utilisation de MySQL n'a pas été paramétrée.<br>\n"
    ."Pour la paramétrer voir le fichier <b>mysqlparams.inc.php.model</b><br>\n");
}
MySql::open(require(__DIR__.'/mysqlparams.inc.php'));

switch ($action) {
  case 'yaml':
    echo "doc=",$geodataDoc->yaml('');
    die("Fin ligne ".__LINE__."\n");
    
  case 'shp':
    SqlLoader::shpFiles($geodataDoc->asArray()['dbpath'], []);
    die("Fin ligne ".__LINE__."\n");
  
  case 'missing':
    $shpPaths = [];
    foreach ($geodataDoc->asArray()['layers'] as $layer)
      $shpPaths[] = $layer['path'];
    SqlLoader::shpFiles($geodataDoc->asArray()['dbpath'], $shpPaths);
    die("Fin ligne ".__LINE__."\n");

  case 'ogrinfo':
    foreach ($lyrpaths as $lyrpath) {
      $ogr = new OgrInfo($lyrpath);
      echo "ogrinfo="; print_r($ogr->info());
    }
    die("Fin ligne ".__LINE__."\n");
  
  case 'fields':
    foreach ($lyrpaths as $lyrpath) {
      $ogr = new OgrInfo($lyrpath);
      $ogrinfo = $ogr->info();
      echo "$lyrpath:\n";
      foreach ($ogrinfo['fields'] as $field)
        echo "  $field[name] $field[type]\n";
    }
    die("Fin ligne ".__LINE__."\n");
    
  case 'create_table':
    $ogr = new OgrInfo($lyrpaths[0]);
    foreach (SqlLoader::create_table($ogr, $tableDef, $geodataDoc->dbname()) as $sql)
      echo "$sql;\n";
    die();
  
  case 'insert_into':
    $dbname = $geodataDoc->dbname();
    $precision = $geodataDoc->asArray()['precision'];
    foreach ($lyrpaths as $lyrpath) {
      $ogr = new Ogr2Php($lyrpath);
      foreach (SqlLoader::insert_into($ogr, $tableDef, $dbname, $precision, 0) as $sql)
        echo "$sql;\n";
    }
    die();

  case 'load':
    echo "Chargement de $lyrname\n";
    $ogrInfo = new OgrInfo($lyrpaths[0]);
    foreach (SqlLoader::create_table($ogrInfo, $tableDef, $geodataDoc->dbname()) as $sql)
      MySql::query($sql);
    foreach ($lyrpaths as $lyrpath) {
      $ogr2php = new Ogr2Php($lyrpath);
      foreach (SqlLoader::insert_into($ogr2php, $tableDef, $geodataDoc->dbname(), $geodataDoc->asArray()['precision'], 0) as $sql)
        MySql::query($sql);
    }
    die();
  
  case 'loadall':
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (!isset($layer['path']))
        continue;
      echo "php $argv[0] $argv[1] load $lyrname\n";
    }
    die();
      
  default:
    die("commande $action inconnue");
}

