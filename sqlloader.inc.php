<?php
/*PhpDoc:
name: sqlloader.inc.php
title: sqlloader.inc.php - module générique de chargement d'un produit dans une base MySQL
includes: [ ogr2php.inc.php, '../geom2d/geom2d.inc.php', '../phplib/srvr_name.inc.php' ]
functions:
doc: |
  La fonction sqlloader() met en oeuvre un chargeur SQL en fonction de paramètres du produit à charger
  Utilisation systématique du type Geometry
  Définition de la variable $sql_reserved_words contenant la liste des mots-clés réservés par SQL à ne pas utiliser comme 
  attribut.
  
journal: |
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
require_once __DIR__.'/ogr2php.inc.php';
require_once __DIR__.'/../geom2d/geom2d.inc.php';

/*PhpDoc: functions
name: list_shpfiles
title: function list_shpfiles($ppath, $dirpath='') - balaie récursivement le répertoire pour retourner la liste des shp files
doc: |
  $ppath est le chemin d'accès au répertoire contenant les fichiers shp/SHP
  Cela peut être un répertoire de répertoires
  Retourne une liste de chemin correspondant à des shpfiles, à partir de $ppath
*/
function list_shpfiles($ppath, $dirpath='') {
//  echo "ppath=$ppath\n";
//  echo "dirpath=$dirpath\n";
//  echo "ppath.dirpath=",$ppath.$dirpath,"\n";
  if (!($dir = @opendir($ppath.$dirpath)))
    throw new Exception("Chemin $ppath$dirpath incorrect pour un répertoire");
//  echo "dir = "; print_r($dir);
  $subdirs = [];
  $shpfiles = [];
  while ($entry = readdir($dir)) {
//    echo "ppath.dirpath.entry = ",$ppath.$dirpath.$entry;
    if (in_array($entry,['.','..']))
      continue;
    elseif (is_dir($ppath.$dirpath.$entry))
      $subdirs[] = $dirpath.$entry;
    elseif (in_array(strtoupper(substr($entry, -4)),['.SHP','.TAB'])) {
//      echo "substr=",substr($entry, -4),"<br>\n";
      $shpfiles[] = $dirpath.$entry;
    }
    elseif (in_array(strtoupper(substr($entry, -4)),['.DAT','.MAP','.IND','.TAR','.TGZ','.SHX','.DBF','.PRJ','.CPG','.TXT','HTML','.MD5','.XML','.PDF']))
      echo ''; // "substr=",substr($entry, -4)," ignoré<br>\n";
    elseif (in_array(strtoupper(substr($entry, -3)),['.7Z']))
      echo ''; // "substr=",substr($entry, -4)," ignoré<br>\n";
    else
      echo "substr=",substr($entry, -4)," non connu<br>\n";
  }
  closedir($dir);
//  echo "<pre>subdirs="; print_r($subdirs);
//  echo "<pre>shpfiles="; print_r($shpfiles);
  foreach ($subdirs as $subdir)
    $shpfiles = array_merge($shpfiles, list_shpfiles($ppath, $subdir.'/'));
  return $shpfiles;
}

// transformation du type Ogr en type SQL
function sqltype($fieldtype) {
  if ($fieldtype=='Integer')
    return 'integer';
  if (preg_match('!^String\((\d+)\)$!', $fieldtype, $matches))
    return "varchar($matches[1])";
  if (preg_match('!^Real\((\d+)\.(\d+)\)$!', $fieldtype, $matches))
    return "decimal($matches[1],$matches[2])";
  throw new Exception("dans sqltype(), type '$fieldtype' inconnu");
}

// liste de mot-clés réservés pour SQL
function sql_reserved_words() { return ['add','ignore']; }

/*
function geomsqltype($geomtype) {
// Les polygones ont des MultiPolygones, je force le type Polygon à être Geometry
  $geomsqltypes = [
    '3D Point'=>'Point',
    'Point'=>'Point',
    '3D Line String'=>'LineString ',
    'Line String'=>'LineString ',
    'Polygon'=>'Geometry',
    '3D Polygon'=>'Geometry',
  ];
  if (!isset($geomsqltypes[$geomtype]))
    throw new Exception("Dans geomsqltype(), geometry='$geomtype' inconnu");
  return $geomsqltypes[$geomtype];
}
*/

/*PhpDoc: functions
name: create_table
title: function create_table($info, $tableDef, $suffix, $mysql_database) - Génération de l'instruction SQL create table
doc: |
  $info correspond à $ogr->info()
  $tableDef correspond à la définition des paramètres pour une table
*/
function create_table($info, $tableDef, $suffix, $mysql_database) {
//  print_r($tableDef);
  if (!isset($info['layername']))
    throw new Exception("ogrinfo incorrect");
  $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
  $table_name = strtolower($info['layername']).$suffix;
  echo "drop table if exists $mysql_database$table_name;\n";
  echo "create table $mysql_database$table_name (\n";
  $sqlfields = [];
  if (isset($info['fields']))
    foreach ($info['fields'] as $field) {
//    print_r($field);
      $name = strtolower($field['name']);
// cas d'utilisation d'un mot-clé SQL comme nom de champ
      if (in_array($name,sql_reserved_words()))
        $name = "col_$name";
      $sqlfields[] = "  $name ".sqltype($field['type']).' not null';
    }
  echo implode(",\n",$sqlfields),",\n";
  echo "  geom Geometry not null\n";
//  echo "  geom Geometry not null\n";
  echo ")\n",
       "ENGINE = MYISAM\n",
       "DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;\n";
  echo "create spatial index ${table_name}_geom on $mysql_database$table_name(geom);\n";
  if (isset($tableDef['indexes']) and $tableDef['indexes'])
    foreach ($tableDef['indexes'] as $index_fields=>$unique) {
      $index_name = str_replace(',','_',$index_fields);
      echo "create ",($unique ? 'unique ':''),"index ${table_name}_${index_name} on $mysql_database$table_name($index_fields);\n";
    }
}

/*PhpDoc: functions
name: insert_into
title: function insert_into($ogr, $table, $suffix, $mysql_database, $precision, $crs, $nbrmax=20) - Génération de l'instruction SQL insert
doc: |
  $tableDef correspond à la définition des paramètres pour une table
*/
function insert_into($ogr, $table, $suffix, $mysql_database, $precision, $crs, $nbrmax=20) {
  $transaction = true; // utilisation des transactions
  $transaction = false; // utilisation des transactions
  $info = $ogr->info();
  if (!isset($info['layername']))
    throw new Exception("ogrinfo incorrect");
  $mysql_database = ($mysql_database ? $mysql_database.'.' : '');
  $table_name = strtolower($info['layername']).$suffix;
  $fields = [];
  foreach ($info['fields'] as $field) {
    $name = strtolower($field['name']);
    if (in_array($name,sql_reserved_words()))
      $name = "col_$name";
    $fields[] = $name;
  }
  Geometry::setParam('precision', $precision);
  echo "truncate $mysql_database$table_name;\n";
  if ($transaction)
    echo "start transaction;\n";
  $nbre = 0;
  foreach ($ogr->features() as $feature) {
    $nbre++;
    if ($transaction and ($nbre % 1000 == 0))
      echo "commit;\n";
    if ($nbrmax and ($nbre > $nbrmax)) {
      if ($transaction)
        echo "commit;\n";
      die("-- Arrêt après $nbrmax");
    }
//    if ($nbre<>14) continue;
    if (!$feature->wkt()) {
      echo "-- feature->wkt()='",$feature->wkt(),"', feature skipped\n";
      continue;
    }
    echo "insert into $mysql_database$table_name(",implode(',',$fields),",geom) values\n";
//    echo "$feature\n";
    $values = [];
    foreach ($fields as $field)
      $values[] = '"'.str_replace('"','""',$feature->field([$field])).'"';
    echo "(",implode(',',$values);
    if (!($geom = GeomCollection::create($feature->wkt())))
      $geom = Geometry::create($feature->wkt());
//    echo "geom=",$geom->wkt(),"\n";
    if ($crs<>'geo')
      $geom = $geom->chgCoordSys($crs, 'geo');
//    echo "geom=$geom\n";
    $wkt = $geom->filter($precision)->wkt();
//    echo "wkt=$wkt\n";
//    die("ligne ".__LINE__);
    echo ",GeomFromText('$wkt'));\n";
  }
  if ($transaction)
    echo "commit;\n";
}

/*PhpDoc: functions
name: sqlloader
title: function sqlloader($ppaths, $datasets, $tables, $precision, $encoding, $mysql_database=null, $params=[]) - chargement d'un produit en tables SQL
doc: |
  - $ppaths est une liste de chemins du produit dont un au moins est correct
  - $datasets est la liste des séries de données composant le produit sous la forme:
      [ name => [ 'title'=>title, 'suffix'=>suffix, 'path'=>path, 'crs'=>crs ]
    où:
    - name est le nom utilisé pour référencer la série de données
    - title est le titre de la série de données
    - suffix est le suffix à ajouter aux noms de table
    - path est le chemin spécifique à la série de données
    - crs est le système de coordonnées utilisé
  - $tables est un tableau de définition des différentes couches du produit structuré de la manière suivante:
      [ name => [ 'path' => path, 'indexes' => [ fields => unique ] ] ]
    où:
    - name est un nom utilisé pour référencer la table
    - path est le chemin d'accès du fichier shp par rapport à $ppaths
    - fields est une chaine contenant les champs concernés par un index séparés par une virgule
    - unique vaut true si l'index est unique, false sinon
  - $precision définit le nombre de chiffres après la virgule à conserver en degrés décimaux
  - $encoding est l'encodage des caractères dans les champs : 'UTF-8' ou 'ISO-8859-1'
  - $mysql_database est le nom de la base MySQL dans laquelle charger les données, par défaut la base de connexion
  - $params est un tableau: [ nom_du_parametre => [ valeurs_possibles ] ]
*/
function sqlloader($ppaths, $datasets, $tables, $precision, $encoding, $mysql_database=null, $params=[]) {
//  echo "<pre>"; foreach (['ppaths', 'datasets', 'tables', 'precision', 'encoding', 'mysql_database', 'params'] as $param) { echo "$param="; print_r($$param); echo ", "; } echo "</pre>\n";
//  die();
  $action_helps = [
    'help' => "Affiche cette aide",
    'list_shpfiles' => "Affiche la liste des fichiers SHP",
    'info {table}' => "Effectue un ogrinfo sur le fichier SHP correspondant à la table en paramètre",
    'create_table {table}' => "Génère les ordres SQL de création de la table en paramètre",
    'create_all_tables' => "Génère les ordres SQL de création de toutes les tables de tous les lots",
    'features {table}' => "Affiche les objets du fichier SHP correspondant à la table en paramètre",
    'insert_into {table}' => "Génère les ordres SQL d'insertion des n-uplets dans la table en paramètre",
    'load_table {table}' => "Génère les ordres SQL de création et d'insertion pour la table en paramètre",
    'load_all' => "Génère les commandes shell (uniquement sur unix) de création et de chargement de toutes les tables",
  ];  
  if (php_sapi_name()<>'cli') {
    $urlparams = '';
    foreach (array_keys($params) as $paramname)
      $urlparams .= "$paramname=$_GET[$paramname]&amp;";
      
    echo "<html><head><meta charset='UTF-8'><title>sqlloader</title></head><body>";
//    echo "<pre>ppaths="; print_r($ppaths); echo "</pre>\n";
    if (!isset($_GET['action'])) {
// S'il existe plusieurs séries de données alors choix d'une d'entre elles
      if ((count($datasets)>1) and !isset($_GET['dsname'])) {
        echo "<h3>Choix d'une série de données</h3><ul>\n";
        foreach ($datasets as $dsname => $dataset)
          echo "<li><a href='?${urlparams}dsname=$dsname'>$dataset[title]</a>\n";
        echo "</ul>\n";
      }
      elseif ($tables) {
        echo "<h3>Tables",(isset($_GET['dsname'])?" pour $_GET[dsname]":''),"</h3><ul>\n";
        $dsparam = (isset($_GET['dsname'])?"&amp;dsname=$_GET[dsname]":'');
        foreach (array_keys($tables) as $table) {
          echo "<li>$table : ";
          foreach (['info','create_table','features','insert_into','load_table'] as $action)
            echo "<a href='?${urlparams}action=$action&amp;table=$table$dsparam'>$action</a>\n";
        }
        echo "</ul>\n";
      }
      else
        echo "Aucune table définie<br>\n";
        
      echo "<h3>Actions globales</h3><ul>\n";
      foreach (['help','list_shpfiles','create_all_tables','load_all'] as $action)
        echo "<li><a href='?${urlparams}action=$action'>$action</a>\n";
      echo "</ul>\n";
      die();
    }
    
    echo "<pre>\n";
    $action = $_GET['action'];
    $table = (isset($_GET['table']) ? $tables[$_GET['table']] : null);
    $dsname = (isset($_GET['dsname']) ? $_GET['dsname'] : null);
    $nbmax = 20;
  }
  else {
// le nbre de paramètres de la commande dépend de la variable $params
// les paramètres définis par la variable params doivent être définis avant les autres
    global $argc, $argv;
//    echo "argc=$argc\n";
//    echo "argv="; print_r($argv);
    $nbparams = count($params);
    $param_names = ($nbparams ? ' {'.implode('} {',array_keys($params)).'}' : '');
    if ($argc <= $nbparams+1) {
      echo "usage: php $argv[0]$param_names {action} [{table}] [{dsname}]\n";
      foreach (array_keys($params) as $no => $param_name)
        echo "avec {",$param_name,"}=",$argv[$no+1],"\n";
      echo <<<EOT
avec une des actions suivantes:
  help
  create_all_tables
  load_all
  create_table {table}
  insert_into {table}
  load_table {table}
  features {table}
  info {table}
  list_shpfiles
avec les tables:

EOT;
      foreach (array_keys($tables) as $table)
        echo "  $table\n";
      if (count($datasets)>1) {
        echo "avec les SD:\n";
        foreach ($datasets as $dsname=>$dataset)
          echo "  $dsname : $dataset[title]\n";
      }
      die();
    }
    $action = $argv[$nbparams+1];
    $table = (($argc>=$nbparams+3) ? $tables[$argv[$nbparams+2]] : null);
    $dsname = (($argc>=$nbparams+4) ? $argv[$nbparams+3] : null);
    $nbmax = 0;
  }

// le chemin est le premier de la liste qui corresponde effectivement à un répertoire
  foreach ($ppaths as $ppath)
    if (is_dir($ppath))
      break;
  if (!is_dir($ppath))
    throw new Exception("Chemins du produit incorrect: ".implode(',',$ppaths));

  if (count($datasets)==1)
    $dsname = array_keys($datasets)[0];
  $dspath = ($dsname ? $datasets[$dsname]['path'] : null);
  $ogr = null;
  if ($table) {
    $ogr = new Ogr2Php($ppath.$dspath.$table['path'], $encoding);
//    echo "info="; print_r($ogr->info());
    if (($action<>'info') and !isset($ogr->info()['layername'])) {
      if (php_sapi_name()=='cli')
        fprintf(STDERR,"table $table[title] $dsname inexistante\n");
      die("-- table $table[title] $dsname inexistante\n");
    }
  }
  
  switch ($action) {
    case 'help':
      foreach ($action_helps as $action => $help)
        echo "$action - $help\n";
      break;
      
// liste les shp files de toutes les séries de données
    case 'list_shpfiles':
      $shpfiles = [];
      foreach ($datasets as $dsname => $dataset)
        foreach (list_shpfiles($ppath.$dataset['path']) as $shpfile)
          if (!in_array($shpfile, $shpfiles))
            $shpfiles[] = $shpfile;
      sort($shpfiles);
      echo "shpfiles="; print_r($shpfiles);
      break;
            
// affiche les infos sur le fichier shp sélectionné
    case 'info':
      echo "ppath=$ppath\n";
      echo "dspath=$dspath\n";
      echo "table_path=$table[path]\n";
      echo "path=",$ppath.$dspath.$table['path'],"\n";
      if (!$table)
        die("Erreur: la table doit etre definie\n");
      echo "info="; print_r($ogr->info());
      return $ogr->info();
      
// génère les ordres SQL de création de la table sélectionnée
    case 'create_table':
      if (!$table)
        die("Erreur: la table doit etre definie\n");
      create_table($ogr->info(), $table, $datasets[$dsname]['suffix'], $mysql_database);
      return $ogr->info();

// génère les ordres SQL de création de toutes les tables
    case 'create_all_tables':
      foreach ($datasets as $dsname => $dataset)
        foreach ($tables as $table) {
          $ogr = new Ogr2Php($ppath.$dataset['path'].$table['path'], $encoding);
          if (!isset($ogr->info()['layername']))
            echo "-- table $table[title] $dsname inexistante\n";
          else
            create_table($ogr->info(), $table, $datasets[$dsname]['suffix'], $mysql_database);
        }
      break;
      
// liste les features du fichier shp sélectionné
    case 'features':
      if (!$table)
        die("Erreur: la table doit etre definie\n");
      $nbre = 0;
      foreach ($ogr->features() as $feature) {
        echo "$feature\n";
        if ($nbmax and (++$nbre >= $nbmax)) die("Arrêt après $nbmax");
      }
      break;
      
// génère les ordres SQL d'insertion de tuples pour le fichier sélectionné
    case 'insert_into':
      if (!$table)
        die("Erreur: la table doit etre definie\n");
      insert_into($ogr, $table, $datasets[$dsname]['suffix'], $mysql_database, $precision, $datasets[$dsname]['crs'], $nbmax);
      break;
      
// définit et charge une table
    case 'load_table':
      if (!$table)
        die("Erreur: la table doit etre definie\n");
      if (php_sapi_name()=='cli')
        fprintf(STDERR,"load_table $table[title] $dsname\n");
      create_table($ogr->info(), $table, $datasets[$dsname]['suffix'], $mysql_database);
      insert_into($ogr, $table, $datasets[$dsname]['suffix'], $mysql_database, $precision, $datasets[$dsname]['crs'], $nbmax);
      return $ogr->info();
      
// Génère un script sh pour charger toutes les tables de toutes les séries de données
// Ne peut fonctionner que sur Alwaysdata et en sapi cli 
    case 'load_all':
      foreach (array_keys($datasets) as $dsname)
        foreach (array_keys($tables) as $table_name) {
          echo "php $argv[0] ";
          foreach (array_keys($params) as $no => $param_name)
            echo $argv[$no+1].' ';
          echo "load_table $table_name $dsname\n";
        }
      break;
      
// Génère un script sh pour charger toutes les tables de toutes les séries de données qui ne sont pas déjà chargées
// Ne peut fonctionner pleinement qu'en cli sur Alwaysdata
// Ne fonctionne que pour geoapi.fr/bdv
    case 'missing':
      $database = (php_sapi_name()=='cli' ? $argv[1] : $_GET['database']);
      if (!($db = yaml_parse(file_get_contents("$database.yaml"))))
        die("Erreur de lecture du fichier '$database.yaml'");
//      print_r($db);
      require dirname(__FILE__).'/../phplib/srvr_name.inc.php';
//      echo "server_name=$server_name\n";
      $mysql_database = $db['mysql_database'][$server_name];
//      die("FIN ligne ".__LINE__);
      require_once 'mysqlprms.inc.php';
      $mysqli = openMySQL($mysql_params);
      $sql = "show tables in $mysql_database";
      if (!($result = $mysqli->query($sql)))
        die("SQL query failed: (" . $mysqli->errno . ") " . $mysqli->error. " on: $sql");
      $existing_tables = [];
      while ($tuple = $result->fetch_array(MYSQLI_NUM)) {
        $existing_tables[] = $tuple[0];
      }
//      die("FIN ligne ".__LINE__);
      if (!$dspaths)
        $dspaths = [''=>''];
      foreach ($dspaths as $dsname => $dspath)
        foreach ($tables as $table_name => $table)
          if (!in_array($table_name.$dsname, $existing_tables)) {
            if (php_sapi_name()=='cli') {
              echo "php $argv[0] ";
              foreach (array_keys($params) as $no => $param_name)
                echo $argv[$no+1].' ';
            }
            echo "load_table $table_name",($dsname?" $dsname":''),"\n";
          }
      break;
      
    default:
      echo "Action $action inconnue\n";
  }
  return null;
}