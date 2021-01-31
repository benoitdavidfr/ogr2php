<?php
/*PhpDoc:
name: sqlloader.php
title: sqlloader.php - module générique de chargement d'un jeu de données dans une base SQL
classes:
doc: |
  Script en 2 parties:
    1) Définition d'une classe SqlLoader pour charger dans une base SQL des fichiers OGR d'un jeu de données en fonction
       de paramètres du jeu de données à charger dans datasets.yaml. Utilisation systématique du type Geometry
    2) Mise en oeuvre du script pour effectuer le chargement de qqs jeux de données définis dans datasets.yaml
  
journal: |
  29-30/1/2021:
    - suppression de l'utilisation de YamlDoc et utilisation à la place un fichier datasets.yaml qui liste les produits
    - restructuration du code autour d'une classe représentant à JD à charger
    - enregistrement de la version du jeu de données et la date de son chargement en commentaire dans chaque table
  6/5/2019:
    - migration de geometry sur gegeom
    - lorsque la géométrie est trop petite, une erreur particulière est affichée au chargement
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
includes: [ '../../phplib/sql.inc.php', ogr2php.inc.php, sqlparams.inc.php ]
*/
//$version = "6/10/2018 18:00";
//$version = "27/4/2019 5:00";
$version = "30/1/2021 20:00";

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../../phplib/sql.inc.php';
require_once __DIR__.'/ogr2php.inc.php';

use Symfony\Component\Yaml\Yaml;

class Chrono {
  protected $startTime;
  protected $prevTime;
  protected $prevNbre=0;
  
  function __construct() {
    $this->startTime = microtime(true);
    $this->prevTime = $this->startTime;
  }
  
  function show(int $nbre) { // affichage du chrono pour $nbre itérations
    $cTime = microtime(true);
    if ((($cTime - $this->startTime) > 0) && (($cTime - $this->prevTime) > 0))
      printf("nbre=%d k, débit moy.=%.1f features/s, débit inst.=%.1f features/s\r",
        $nbre/1000, $nbre / ($cTime - $this->startTime), ($nbre - $this->prevNbre) / ($cTime - $this->prevTime));
    $this->prevTime = $cTime;
    $this->prevNbre = $nbre;
  }
};

/*PhpDoc: classes
name:  SqlLoader
title: class SqlLoader - Classe regroupant les fonctions utiles au chargement
methods:
doc: |
  Un objet correspond à un chargement
*/
class SqlLoader {
  const SQL_RESERVED_WORDS = ['add','ignore'];
  // les chemins possibles pour le répertoire des données
  const DATA_STORE_PATHS = [
    '/home/bdavid/www/data',
    '/var/www/html/data',
  ];

  protected string $id; // id du dataset dans datasets
  protected array $dataset; // le dataset à charger décrit comme spécifié par le schéma
  protected ?string $lyrName; // la couche concernée
  protected string $server; // serveur dans lequel charger la couche
  
  static function dataStorePath() {
    foreach (self::DATA_STORE_PATHS as $dataStorePath)
      if (is_dir($dataStorePath))
        return $dataStorePath;
  }
  
  function __construct(string $id, array $dataset, ?string $lyrName, string $server) {
    $this->id = $id;
    $this->dataset = $dataset;
    $this->lyrName = $lyrName;
    $this->server = $server;
    if (!isset($dataset['sqlSchemas'][$server]))
      throw new Exception("Le serveur $server défini dans sqlparams.inc.php n'est pas prévu pour charger $id\n");
    $dbname = $dataset['sqlSchemas'][$server];
    Sql::open("$server/$dbname");
  }
  
  function layers(): array { return $this->dataset['layers']; }
  
  function asArray(): array {
    return [
      'id'=> $this->id,
      'dataset'=> $this->dataset,
      'server'=> $this->server,
    ];
  }
  
  static function query(string $sql): void {
    try {
      Sql::query($sql);
    } catch(Exception $e) {
      echo "Erreur SQL: ",$e->getMessage(),"\n";
    }
  }
  
  // liste les fichiers SHP absent de $shpPaths
  // $path est la liste des répertoires intermédiaires sans / ni en début ni en fin
  function shpFiles(array $shpPaths=[], string $path=''): void {
    //echo "shpFiles($dbpath, shpPaths)\n";
    $dbpath = $this->dataset['dbpath'];
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
        $this->shpFiles($shpPaths, $path ? "$path/$entry" : $entry);
      }
    }
    $dir->close();
  }
  
  // liste les fichiers SHP absents de la description du jeu
  function missing(): void {
    $shpPaths = [];
    foreach ($this->dataset['layers'] as $layer)
      if (isset($layer['ogrPath']))
        $shpPaths[] = $layer['ogrPath'];
    $this->shpFiles($shpPaths);
  }
  
  // liste des paths correspondants à la layer
  function lyrPaths(): array {
    $lyrName = $this->lyrName;
    if (!$lyrName)
      return [];
    if (!isset($this->dataset['layers'][$lyrName]))
      throw new Exception("Erreur: layer $lyrName inconnue dans le jeu $this->id");
    $tableDef = $this->dataset['layers'][$lyrName];
    $tableDef['_id'] = $lyrName;
    
    $dbpath = $this->dataset['dbpath'];
    if (is_string($tableDef['ogrPath']))
      $lyrpaths = [ self::dataStorePath()."/$dbpath/$tableDef[ogrPath]" ];
    else {
      $lyrpaths = [];
      foreach ($tableDef['ogrPath'] as $path)
        $lyrpaths[] = self::dataStorePath()."/$dbpath/$path";
    }
    return $lyrpaths;
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
  
  function comment(): string { // fabrication du commentaire de la table fondé sur des MD DublinCore en JSON
    $comment = [];
    foreach (['title','publisher','conformsTo','issued','spatial','format','identifier'] as $key) {
      if (isset($this->dataset[$key]))
        $comment[$key] = $this->dataset[$key];
    }
    $comment['loaded'] = "loaded by sqlloader.php on ".date(DATE_ATOM);
    //echo json_encode($comment, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
    return json_encode($comment, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  }
  
  /*PhpDoc: methods
  name: create_table
  title: "function create_table(OgrInfo $ogr, array $tableDef, string $mysql_database): array - instructions SQL create table"
  doc: |
    retourne une liste des requêtes Sql, chacun étant un array d'éléments, chaque élément est soit un string,
    soit un dict [{soft}=> string]
  */
  function create_table(OgrInfo $ogr): array {
    $tableDef = $this->dataset['layers'][$this->lyrName];
    //print_r($tableDef);
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $table_name = $this->lyrName;
    $sqls[0] = "drop table if exists $table_name";
    $sql = ["create table $table_name (\n"];
    $sqlfields = [];
    if (isset($tableDef['idpkey'])) {
      $sql [] = [
        'MySql'=> "  _idpkey int not null auto_increment primary key,\n",
        'PgSql'=> "  _idpkey serial primary key,\n",
      ];
    }
    if (isset($info['fields']))
      foreach ($info['fields'] as $field) {
        if (in_array($field['name'], $tableDef['excludedFields'] ?? []))
          continue;
        $sqltype = $tableDef['fieldtypes'][$field['name']] ?? self::sqltype($field['type']);
        //print_r($field);
        $name = strtolower($field['name']);
        // cas d'utilisation d'un mot-clé SQL comme nom de champ
        if (in_array($name, self::SQL_RESERVED_WORDS))
          $name = "col_$name";
        $sqlfields[] = "  $name $sqltype not null";
      }
    $sql[] = implode(",\n",$sqlfields).",\n";
    $sql[] = [
      'MySql'=> "  geom Geometry not null\n"
        .")\n"
        ."comment='".str_replace("'","''",$this->comment())."'\n"
        ."ENGINE = MYISAM\n"
        ."DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci",
      'PgSql'=> "  geom Geography not null\n"
        . ")\n",
    ];
    $sqls[] = $sql;
    $sqls[] = [
      [
        'MySql'=> "create spatial index ${table_name}_geom on $table_name(geom)",
      ]
    ];
    
    if (isset($tableDef['indexes'])) {
      static $indexCreate = [
        'primary'=> 'primary key',
        'unique'=> 'unique',
        'multiple'=> 'index',
      ];
      foreach ($tableDef['indexes'] as $index_fields => $typeIndex) {
        $index_name = str_replace(',','_',$index_fields);
        //ALTER TABLE `limite_administrative` ADD PRIMARY KEY(`id_rte500`);
        //ALTER TABLE `limite_administrative` ADD INDEX(`nature`)
        //ALTER TABLE `limite_administrative` ADD UNIQUE(`nature`);
        $sqls[] = [['MySql'=> "alter table $table_name add ".$indexCreate[$typeIndex]."($index_fields)"]];
      }
    }
    return $sqls;
  }
  
  /*PhpDoc: methods
  name: truncate_table
  title: "static function truncate_table(array $tableDef): array - instruction SQL truncate table"
  doc: |
    $tableDef correspond à la définition des paramètres pour une table
  */
  static function truncate_table(array $tableDef): string {
    //print_r($tableDef);
    return "truncate $tableDef[_id]";
  }
  
  // insert les enregistrements en créant le sql en mémoire
  /*static function insert_into(Ogr2Php $ogr, array $tableDef, int $precision, int $nbrmax=20): array {
    $transaction = true; // utilisation des transactions
    //$transaction = false; // utilisation des transactions
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $table_name = $tableDef['_id'];
    $fields = [];
    foreach ($info['fields'] as $field) {
      if (isset($tableDef['excludedFields']) && in_array($field['name'], $tableDef['excludedFields']))
        continue;
      $name = strtolower($field['name']);
      if (in_array($name, self::SQL_RESERVED_WORDS))
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
      $sql = "insert into $table_name(".implode(',',$fields).",geom) values\n";
      $values = [];
      foreach ($fields as $propname => $field)
        $values[] = "'".str_replace("'","''",$feature->properties[$propname])."'";
      if (!$feature->geometry) {
        echo "geométrie vide pour :",implode(',',$values),"\n";
        continue;
      }
      $sql .= "(".implode(',',$values);
      $geom0 = $feature->geometry->proj2D();
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
  }*/
  
  
  // insert les enregistrements ss créer le sql en mémoire
  function insert_into2(Ogr2Php $ogr): void {
    $chrono = new Chrono;
    $transaction = true; // utilisation des transactions
    //$transaction = false; // utilisation des transactions
    $nbrmax = $this->dataset['layers'][$this->lyrName]['nbreMax'] ?? 0;
    if ($nbrmax)
      printf("nbrmax=%.1f k\n", $nbrmax/1000);
    $skip = $this->dataset['layers'][$this->lyrName]['skip'] ?? 0;
    if ($skip)
      printf("skip=%.1f k\n", $skip/1000);
    $info = $ogr->info();
    if (!isset($info['layername']))
      throw new Exception("ogrinfo incorrect");
    $table_name = $this->lyrName;
    $tableDef = $this->dataset['layers'][$this->lyrName];
    $fields = [];
    foreach ($info['fields'] as $field) {
      if (in_array($field['name'], $tableDef['excludedFields'] ?? []))
        continue;
      $name = strtolower($field['name']);
      if (in_array($name, self::SQL_RESERVED_WORDS))
        $name = "col_$name";
      $fields[$field['name']] = $name;
    }
    if ($transaction)
      self::query('start transaction');
    $nbre = 0;
    foreach ($ogr as $feature) {
      $nbre++;
      if (($skip <> 0) && ($nbre <= $skip)) {
        if ($nbre % 10000 == 0)
          $chrono->show($nbre);
        continue;
      }
      if (($nbre % 1000 == 0)) {
        if ($transaction)
          self::query('commit');
        $chrono->show($nbre);
      }
      if ($nbrmax && ($nbre > $nbrmax + $skip)) {
        if ($transaction)
          self::query('commit');
        $chrono->show($nbre-1);
        echo "\n";
        return;
      }
      //echo "feature=$feature\n";
      $sql = "insert into $table_name(".implode(',',$fields).",geom) values\n";
      $values = [];
      foreach ($fields as $propname => $field) {
        $values[] = "'".str_replace("'","''",$feature->properties()[$propname])."'";
      }
      if (!$feature->geometry()) {
        echo "geométrie vide pour :",implode(',',$values),"\n";
        continue;
      }
      $sql .= "(".implode(',',$values);
      $geom0 = $feature->geometry()->proj2D();
      $geom = $geom0->filter($this->dataset['precision']);
      if (!$geom) {
        echo "geometry trop petite pour :",implode(',',$values),"\n";
        $geom = $feature->geometry()->proj2D();
      }
      elseif (!$geom->isValid()) {
        //echo "geometry non filtré=$geom0\n";
        //echo "geometry=",$geom->wkt(),"\n";
        //throw new Exception("geometry invalide ligne ".__LINE__);
        echo "geometry invalide pour :",implode(',',$values),"\n";
        echo "erreurs: ",json_encode($geom->getErrors()),"\n";
        continue;
      }
      $wkt = $geom->wkt();
      // Utilisation du SRID 0 pour éviter que certaines fonctions comme ST_Envelope() ne fonctionnent pas
      $sql .= ",ST_GeomFromText('$wkt', 0))"; 
      self::query($sql);
    }
    if ($transaction)
      self::query('commit');
    $chrono->show($nbre);
    echo "\n";
    return;
  }
  
  /*PhpDoc: methods
  name: drop_table
  title: "static function drop_table(array $tableDef): string - instruction SQL drop table"
  */
  function drop_table(): string {
    return "drop table if exists ".$this->lyrName;
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


// les différentes actions proposées [code => [title, param]]
$menu = [
  'yaml'=> ['title'=> "affiche le document Yaml"],
  'shp'=> ['title'=> "affiche la liste des fichiers SHP"],
  'missing'=> ['title'=> "affiche la liste des fichiers SHP absent du document"],
  'ogrinfo'=> ['title'=> "effectue un ogrinfo sur la couche", 'param'=> true],
  'fields'=> ['title'=> "liste les champs de la couche", 'param'=> true],
  'sql_create'=> ['title'=> "génère les ordres SQL pour créer la table de la couche", 'param'=> true],
  'load'=> ['title'=> "crée la table pour la couche et la peuple", 'param'=> true],
  'drop'=> ['title'=> "supprime la table de la couche", 'param'=> true],
  'loadall'=> ['title'=> "génère les ordres sh pour créer ttes les tables et les peupler"],
];

ini_set('memory_limit', '1G');
//ini_set('memory_limit', '10G');

$datasets = Yaml::parseFile(__DIR__.'/datasets.yaml')['datasets'];

if (php_sapi_name() == 'cli') {
  if ($argc <= 1) {
    //echo "argc=$argc\n";
    //print_r($argv);
    echo "usage: $argv[0] <dataset> [<cmde> [<layer>]]\n";
    echo "où <dataset> vaut:\n";
    foreach ($datasets as $id => $dataset)
      echo "  - $id - pour $dataset[title]\n";
    die("version $version\n");
  }
  elseif ($argc == 2) {
    $datasetid = $argv[1];
    echo "usage: $argv[0] $argv[1] [<cmde> [<layer>]]\n";
    if (!isset($datasets[$datasetid]))
      die("Produit $datasetid non défini\n");
    echo "où <cmde> vaut:\n";
    foreach ($menu as $code => $action)
      echo "  $code ",isset($action['param']) ? '<layer> ': '', "- $action[title]\n";
    echo "où <layer> vaut:\n";
    foreach ($datasets[$datasetid]['layers'] as $lyrname => $layer)
      echo "  $lyrname pour $layer[title]\n";
    die();
  }
  elseif (($argc == 3) && !in_array($argv[2], ['yaml','shp','missing','loadall'])) {
    $datasetid = $argv[1];
    echo "usage: $argv[0] $datasetid $argv[2] <layer>\n";
    echo "où <layer> vaut:\n";
    foreach ($datasets[$datasetid]['layers'] as $lyrname => $layer)
      echo "  $lyrname pour $layer[title]\n";
    die();
  }
  else {
    $datasetid = $argv[1];
    $action = $argv[2];
    $lyrname = $argv[3] ?? null;
  }
}
else { // php_sapi_name() != 'cli'
  ini_set('max_execution_time', 600);
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>sqlooader</title></head><body><pre>\n";
  if (!isset($_GET['dataset'])) {
    echo "</pre><h3>Jeux de données possibles:</h3>\n";
    foreach($datasets as $id => $dataset)
      echo "<a href='?dataset=$id'>$dataset[title]</a><br>";
    die("<br>version $version\n");
  }
  elseif (!isset($_GET['action'])) {
    echo "</pre><h3>Actions possibles:</h3>\n";
    foreach ($menu as $code => $action)
      echo "<a href='?dataset=$_GET[dataset]&amp;action=$code'>$action[title]</a><br>";
    die();
  }
  elseif (!in_array($_GET['action'], ['yaml','shp','missing','loadall']) && !isset($_GET['layer'])) {
    echo "</pre><h3>$_GET[action] sur quelle couche ?</h3>\n";
    foreach ($dataset['layers'] as $lyrname => $layer) {
      echo "<a href='?dataset=$_GET[dataset]&amp;action=$_GET[action]&amp;layer=$lyrname'>$layer[title]</a><br>\n";
    }
    die();
  }
  else {
    $datasetid = $_GET['dataset'];
    $action = $_GET['action'];
    $lyrname = $_GET['layer'] ?? null;
  }
}

if (!file_exists(__DIR__.'/sqlparams.inc.php')) {
  die("Cette commande n'est pas disponible car l'utilisation de SQL n'a pas été paramétrée.<br>\n"
    ."Pour la paramétrer voir le fichier <b>sqlparams.inc.php.model</b><br>\n");
}
$server = require __DIR__.'/sqlparams.inc.php';

$dataset = new SqlLoader($datasetid, $datasets[$datasetid], $lyrname, $server);
//print_r($dataset);

switch ($action) {
  case 'yaml': {
    echo Yaml::dump($dataset->asArray(), 10, 2);
    die();
  }
    
  case 'shp': {
    $dataset->shpFiles();
    die();
  }
  
  case 'missing': {
    $dataset->missing();
    die();
  }

  case 'ogrinfo': {
    foreach ($dataset->lyrPaths() as $lyrpath) {
      try {
        $ogr = new OgrInfo($lyrpath);
        echo "ogrinfo="; print_r($ogr->info());
      }
      catch(Exception $e) {
        echo $e->getMessage(),"\n  sur $lyrpath\n";
      }
    }
    die("Fin ligne ".__LINE__."\n");
  }
  
  case 'fields': {
    foreach ($dataset->lyrPaths() as $lyrpath) {
      $ogr = new OgrInfo($lyrpath);
      $ogrinfo = $ogr->info();
      echo "$lyrpath:\n";
      foreach ($ogrinfo['fields'] as $field)
        echo "  $field[name] $field[type]\n";
    }
    die();
  }
    
  case 'sql_create': {
    $ogr = new OgrInfo($dataset->lyrPaths()[0]);
    foreach ($dataset->create_table($ogr) as $sql) {
      //print_r($sql);
      echo Sql::toString($sql).";\n";
    }
    die();
  }
  
  case 'load': { // éxécute les ordres SQL dès qu'ils sont définis
    echo "Chargement de $lyrname\n";
    $ogrInfo = new OgrInfo($dataset->lyrPaths()[0]);
    //$skip = $dataset->layers()[$lyrname]['skip'] ?? 0;
    //if ($skip)
      foreach ($dataset->create_table($ogrInfo) as $sql)
        Sql::query($sql);
    foreach ($dataset->lyrPaths() as $lyrpath) {
      $dataset->insert_into2(new Ogr2Php($lyrpath));
    }
    die();
  }
  
  case 'drop': {
    Sql::query($dataset->drop_table());
    die();
  }
  
  case 'loadall': {
    if (php_sapi_name() <> 'cli')
      die("cmde a faire en CLI\n");
    foreach ($dataset->layers() as $lyrname => $layer) {
      if (!isset($layer['ogrPath']))
        continue;
      echo "php $argv[0] $datasetid load $lyrname\n";
    }
    die();
  }
      
  default:
    die("commande $action inconnue");
}

