<?php
/*PhpDoc:
name: sqlloader.php
title: sqlloader.php - module générique de chargement d'un produit dans une base SQL
includes: [ ogr2php.inc.php, '../phplib/sql.inc.php', ../yamldoc/inc.php, sqlparams.inc.php ]
classes:
doc: |
  Script en 2 parties:
    1) Définition d'une classe SqlLoader qui met en oeuvre un chargeur SQL en fonction de paramètres du produit
       à charger. Utilisation systématique du type Geometry
    2) Mise en oeuvre du script pour effectuer le chargement de qqs produits définis dans YamlDoc
  
journal: |
  6/5/2019:
    - migration de geometry sur gegeom
  27/4/2019:
    - jout possibilité de chargement en PgSql
  6/10/2018:
    - ajout RPG2016
    - chargement d'un fichier sans générer ts les ordres SQL en mémoire
  21/8/2018:
    - amélioration de la version non CLI
  20/8/2018:
    - ajout possibilité d'insérer les données sans créer la table
  17/8/2018:
    - réparation a minima de la version non CLI
  15/8/2018:
    - adaptation à la structure des documents VectorDataset, chgt du champ path en ogrPath
  14/8/2018:
    - ajout possibilité de forcer le type SQL d'un champ pour corriger des erreurs
    - ajout possibilité d'exclure certains champs du chargement
    - ajout commande drop_table
  13/8/2018
    - intégration du nom de la base comme paramètre d'appel de sqlloader
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
//$version = "6/10/2018 18:00";
$version = "27/4/2019 5:00";

require_once __DIR__.'/../phplib/sql.inc.php';
require_once __DIR__.'/ogr2php.inc.php';

/*PhpDoc: classes
name:  SqlLoader
title: class SqlLoader - Classe statique regroupant les fonctions utiles au chargement
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
  // $path est la liste des répertoires intermédiaires sans / ni en début ni en fin
  static function shpFiles(string $dbpath, array $shpPaths, string $path=''): void {
    //echo "shpFiles($dbpath, shpPaths)\n";
    $dirpath = SqlLoader::dataStorePath()."/$dbpath/$path";
    $dir = dir($dirpath);
    while (false !== ($entry = $dir->read())) {
      //echo "$entry\n";
      if (is_file("$dirpath/$entry")) {
        $ext = substr($entry, strrpos($entry, '.')+1);
        $pathentry = ($path ? "$path/$entry" : $entry);
        if (in_array(strtoupper($ext), ['SHP']) && !in_array($pathentry, $shpPaths))
          echo "$entry\n";
      }
      elseif (is_dir("$dirpath/$entry") && !in_array($entry,['.','..'])) {
        self::shpFiles($dbpath, $shpPaths, $path ? "$path/$entry" : $entry);
      }
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
        $sqltype = (isset($tableDef['fieldtypes'][$field['name']])) ?
            $tableDef['fieldtypes'][$field['name']]
              : self::sqltype($field['type']);
  //    print_r($field);
        $name = strtolower($field['name']);
  // cas d'utilisation d'un mot-clé SQL comme nom de champ
        if (in_array($name, self::$sql_reserved_words))
          $name = "col_$name";
        $sqlfields[] = "  $name $sqltype not null";
      }
    $sql .= implode(",\n",$sqlfields).",\n";
    if (Sql::software() == 'MySql') {
      $sql .= "  geom Geometry not null\n";
      $sql .= ")\n"
           ."ENGINE = MYISAM\n"
           ."DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
    }
    else {
      $sql .= "  geom Geography not null\n"
            . ")\n";
    }
    $sqls[] = $sql;
    if (Sql::software() == 'MySql') {
      $sqls[] = "create spatial index ${table_name}_geom on $mysql_database$table_name(geom)";
      if (isset($tableDef['indexes']) and $tableDef['indexes']) {
        foreach ($tableDef['indexes'] as $index_fields=>$unique) {
          $index_name = str_replace(',','_',$index_fields);
          $sqls[] = "create ".($unique ? 'unique ':'')
            ."index ${table_name}_${index_name} on $mysql_database$table_name($index_fields)";
        }
      }
    }
    return $sqls;
  }
  
  /*PhpDoc: methods
  name: truncate_table
  title: "static function truncate_table(OgrInfo $ogr, array $tableDef, string $mysql_database): array - instruction SQL truncate table"
  doc: |
    $tableDef correspond à la définition des paramètres pour une table
  */
  static function truncate_table(array $tableDef, string $mysql_database): string {
    //print_r($tableDef);
    $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
    $table_name = $tableDef['_id'];
    return "truncate $mysql_database$table_name";
  }
  
  // insert les enregistrements en créant le sql en mémoire
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
        $values[] = "'".str_replace("'","''",$feature->property($propname))."'";
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
      $sqls[] = $sql;
    }
    if ($transaction)
      $sqls[] = "commit";
    return $sqls;
  }
  
  static function query(string $sql): void {
    try {
      Sql::query($sql);
    } catch(Exception $e) {
      echo "Erreur SQL: ",$e->getMessage(),"\n";
    }
  }
  
  // insert les enregistrements ss créer le sql en mémoire
  static function insert_into2(Ogr2Php $ogr, array $tableDef, string $mysql_database, int $precision, int $nbrmax=20): void {
    //echo "precision=$precision\n";
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
    //$sqls = ["truncate $mysql_database$table_name"];
    if ($transaction)
      self::query('start transaction');
    $nbre = 0;
    foreach ($ogr as $feature) {
      $nbre++;
      if ($transaction && ($nbre % 1000 == 0))
        self::query('commit');
      if ($nbrmax && ($nbre > $nbrmax)) {
        if ($transaction)
          self::query('commit');
        return;
      }
      //echo "feature=$feature\n";
      $sql = "insert into $mysql_database$table_name(".implode(',',$fields).",geom) values\n";
      $values = [];
      foreach ($fields as $propname => $field) {
        //$values[] = '"'.str_replace('"','""',$feature->property($propname)).'"';
        $values[] = "'".str_replace("'","''",$feature->property($propname))."'";
      }
      if (!$feature->geometry()) {
        echo "geométrie vide pour :",implode(',',$values),"\n";
        continue;
      }
      $sql .= "(".implode(',',$values);
      $geom0 = $feature->geometry()->proj2D();
      $geom = $geom0->filter($precision);
      if (!$geom->isValid()) {
        //echo "geometry non filtré=$geom0\n";
        //echo "geometry=",$geom->wkt(),"\n";
        //throw new Exception("geometry invalide ligne ".__LINE__);
        echo "geometry invalide pour :",implode(',',$values),"\n";
        continue;
      }
      $wkt = $geom->wkt();
      $sql .= ",ST_GeomFromText('$wkt'))";
      self::query($sql);
    }
    if ($transaction)
      self::query('commit');
    return;
  }
  
  /*PhpDoc: methods
  name: drop_table
  title: "static function drop_table(OgrInfo $ogr, array $tableDef, string $mysql_database): string - instruction SQL drop table"
  */
  static function drop_table(array $tableDef, string $mysql_database): string {
    $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
    $table_name = $tableDef['_id'];
    $sql = "drop table if exists $mysql_database$table_name";
    return $sql;
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

if (php_sapi_name()<>'cli') {
  ini_set('max_execution_time', 600);
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>sqlooader2</title></head><body><pre>\n";
}
ini_set('memory_limit', '1280M');
//ini_set('memory_limit', '12800M');

require_once __DIR__.'/../yamldoc/inc.php';

// les différents documents décrivant des SD
$docs = [
  'route500'=> "Route500",
  'ne_110m'=> "Natural Earth 110m",
  'ne_10m'=> "Natural Earth 10m",
  'rpg2016'=> "RPG2016",
  'clc2012'=> "CLC 2012",
];
// les différentes actions proposées [code => [title, param]]
$actions = [
  'yaml'=> ['title'=> "affiche le document Yaml"],
  'shp'=> ['title'=> "affiche la liste des fichiers SHP"],
  'missing'=> ['title'=> "affiche la liste des fichiers SHP absent du document"],
  'ogrinfo'=> ['title'=> "effectue un ogrinfo sur la couche", 'param'=> true],
  'fields'=> ['title'=> "liste les champs de la couche", 'param'=> true],
  'sql_create'=> ['title'=> "génère les ordres SQL pour créer la table de la couche", 'param'=> true],
  'sql_insert'=> ['title'=> "génère les ordres SQL pour peupler la table de la couche", 'param'=> true],
  'insert'=> ['title'=> "vide (truncate) puis peuple (insert) la table de la couche", 'param'=> true],
  'load'=> ['title'=> "crée la table pour la couche et la peuple", 'param'=> true],
  'drop'=> ['title'=> "supprime la table de la couche", 'param'=> true],
  'loadall'=> ['title'=> "génère les ordres sh pour créer ttes les tables et les peupler"],
];

if (php_sapi_name() == 'cli') {
  if ($argc <= 1) {
    //echo "argc=$argc\n";
    //print_r($argv);
    echo "usage: $argv[0] <doc> [<cmde> [<layer>]]\n";
    echo "où <doc> vaut:\n";
    foreach ($docs as $id => $title)
      echo "$id - pour $title\n";
    die("version $version\n");
  }
  elseif ($argc == 2) {
    echo "usage: $argv[0] $argv[1] [<cmde> [<layer>]]\n";
    $docid = "geodata/$argv[1]";
    echo "doc est $docid\n";
    Store::setStoreid('pub'); // le store dans lequel est le doc
    if (!($geodataDoc = new_doc($docid)))
      die("$docid inexistant dans le store\n");
    echo "où <cmde> vaut:\n";
    foreach ($actions as $code => $action)
      echo "  $code ",isset($action['param'])?"<layer> ":'',"- $action[title]\n";
    echo "où <layer> vaut:\n";
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['ogrPath']))
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
      if (isset($layer['ogrPath']))
        echo "  $lyrname pour $layer[title]\n";
    }
    die();
  }
  else {
    $docid = $argv[1];
    $action = $argv[2];
    $lyrname = isset($argv[3]) ? $argv[3] : null;
  }
}
else { // php_sapi_name() != 'cli'
  if (!isset($_GET['doc'])) {
    echo "</pre><h3>Documents possibles:</h3>\n";
    foreach($docs as $id => $title)
      echo "<a href='?doc=$id'>$title</a><br>";
    die("<br>version $version\n");
  }
  elseif (!isset($_GET['action'])) {
    echo "</pre><h3>Actions possibles:</h3>\n";
    foreach ($actions as $code => $action)
      echo "<a href='?doc=$_GET[doc]&amp;action=$code'>$action[title]</a><br>";
    die();
  }
  elseif (!in_array($_GET['action'], ['yaml','shp','missing','loadall']) && !isset($_GET['layer'])) {
    Store::setStoreid('pub'); // le store dans lequel est le doc
    if (!($geodataDoc = new_doc("geodata/$_GET[doc]")))
      die("geodata/$_GET[doc] inexistant dans le store\n");
    echo "</pre><h3>$_GET[action] sur quelle couche ?</h3>\n";
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (isset($layer['ogrPath']))
        echo "<a href='?doc=$_GET[doc]&amp;action=$_GET[action]&amp;layer=$lyrname'>$layer[title]</a><br>\n";
    }
    die();
  }
  else {
    
  }
  $docid = $_GET['doc'];
  $action = $_GET['action'];
  $lyrname = isset($_GET['layer']) ? $_GET['layer'] : null;
}

Store::setStoreid('pub'); // le store dans lequel est le doc
if (!($geodataDoc = new_doc("geodata/$docid")))
  die("$docid inexistant dans le store pub\n");

if ($lyrname) {
  if (!isset($geodataDoc->asArray()['layers'][$lyrname]))
    die("Erreur: layer $lyrname inconnue\n");
  $tableDef = $geodataDoc->asArray()['layers'][$lyrname];
  $tableDef['_id'] = $lyrname;
  if (is_string($tableDef['ogrPath']))
    $lyrpaths = [ SqlLoader::dataStorePath().'/'.$geodataDoc->asArray()['dbpath'].'/'.$tableDef['ogrPath'] ];
  else {
    $lyrpaths = [];
    foreach ($tableDef['ogrPath'] as $path)
      $lyrpaths[] = SqlLoader::dataStorePath().'/'.$geodataDoc->asArray()['dbpath'].'/'.$path;
  }
}

if (!file_exists(__DIR__.'/sqlparams.inc.php')) {
  die("Cette commande n'est pas disponible car l'utilisation de SQL n'a pas été paramétrée.<br>\n"
    ."Pour la paramétrer voir le fichier <b>sqlparams.inc.php.model</b><br>\n");
}
Sql::open(require __DIR__.'/sqlparams.inc.php');

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
      if (isset($layer['ogrPath']))
        $shpPaths[] = $layer['ogrPath'];
    SqlLoader::shpFiles($geodataDoc->asArray()['dbpath'], $shpPaths);
    die("Fin ligne ".__LINE__."\n");

  case 'ogrinfo':
    foreach ($lyrpaths as $lyrpath) {
      try {
        $ogr = new OgrInfo($lyrpath);
        echo "ogrinfo="; print_r($ogr->info());
      }
      catch(Exception $e) {
        echo $e->getMessage(),"\n  sur $lyrpath\n";
      }
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
    
  case 'sql_create':
    $ogr = new OgrInfo($lyrpaths[0]);
    foreach (SqlLoader::create_table($ogr, $tableDef, $geodataDoc->dbname()) as $sql)
      echo "$sql;\n";
    die();
  
  case 'sql_insert':
    $dbname = $geodataDoc->dbname();
    $precision = $geodataDoc->asArray()['precision'];
    foreach ($lyrpaths as $lyrpath) {
      $ogr = new Ogr2Php($lyrpath);
      foreach (SqlLoader::insert_into($ogr, $tableDef, $dbname, $precision, 0) as $sql)
        echo "$sql;\n";
    }
    die();

  case 'insert':
    echo "Insertion des données de $lyrname\n";
    Sql::query(SqlLoader::truncate_table($tableDef, $geodataDoc->dbname()));
    foreach ($lyrpaths as $lyrpath) {
      $ogr2php = new Ogr2Php($lyrpath);
      $precision = $geodataDoc->asArray()['precision'];
      foreach (SqlLoader::insert_into($ogr2php, $tableDef, $geodataDoc->dbname(), $precision, 0) as $sql) {
        try {
          Sql::query($sql);
        } catch(Exception $e) {
          echo "Erreur SQL: ",$e->getMessage(),"\n";
        }
      }
    }
    die();

  case 'load0': // génère inutilement tous les ordres SQL en mémoire
    echo "Chargement de $lyrname\n";
    $ogrInfo = new OgrInfo($lyrpaths[0]);
    foreach (SqlLoader::create_table($ogrInfo, $tableDef, $geodataDoc->dbname()) as $sql)
      Sql::query($sql);
    foreach ($lyrpaths as $lyrpath) {
      $ogr2php = new Ogr2Php($lyrpath);
      $precision = $geodataDoc->asArray()['precision'];
      foreach (SqlLoader::insert_into($ogr2php, $tableDef, $geodataDoc->dbname(), $precision, 0) as $sql) {
        try {
          Sql::query($sql);
        } catch(Exception $e) {
          echo "Erreur SQL: ",$e->getMessage(),"\n";
        }
      }
    }
    die();
    
  case 'load': // éxécute les ordres SQL dès qu'ils sont définis
    echo "Chargement de $lyrname\n";
    $ogrInfo = new OgrInfo($lyrpaths[0]);
    foreach (SqlLoader::create_table($ogrInfo, $tableDef, $geodataDoc->dbname()) as $sql)
      Sql::query($sql);
    foreach ($lyrpaths as $lyrpath) {
      $ogr2php = new Ogr2Php($lyrpath);
      $precision = $geodataDoc->asArray()['precision'];
      SqlLoader::insert_into2($ogr2php, $tableDef, $geodataDoc->dbname(), $precision, 0);
    }
    die();
  
  case 'drop_table':
    $sql = SqlLoader::drop_table($tableDef, $geodataDoc->dbname());
    Sql::query($sql);
    die();
  
  case 'loadall':
    $phpfile = php_sapi_name() == 'cli' ? $argv[0] : basename($_SERVER['SCRIPT_FILENAME']);
    foreach ($geodataDoc->asArray()['layers'] as $lyrname => $layer) {
      if (!isset($layer['ogrPath']))
        continue;
      echo "php $phpfile $docid load $lyrname\n";
    }
    die();
      
  default:
    die("commande $action inconnue");
}

