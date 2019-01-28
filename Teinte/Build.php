<?php
/**
 * Pilot to generate different formats
 */
// cli usage
Teinte_Build::deps();
set_time_limit(-1);


if (realpath($_SERVER['SCRIPT_FILENAME']) != realpath(__FILE__)) {
  // file is included do nothing
}
else if (php_sapi_name() == "cli") {
  Teinte_Build::cli();
}
class Teinte_Build
{
  /** True if dependancies are verified and loaded */
  private static $_deps;
  /** Path of kindlegen binary */
  static private $_kindlegen;
  /** Possible formats */
  static private $_formats = array(
    'tei' => '.xml',
    'epub' => '.epub',
    'kindle' => '.mobi',
    'markdown' => '.md',
    'iramuteq' => '.txt',
    'html' => '.html',
    'article' => '_art.html',
    'toc' => '_toc.html',
    // 'docx' => '.docx',
  );
  /** Columns reference to build queries */
  static $coldoc = array("code", "filemtime", "filesize", "deps", "title", "byline", "editby", "date", "source", "identifier", "publisher", "class" );
  /** Sqlite persistency */
  static $create = "
PRAGMA encoding = 'UTF-8';
PRAGMA page_size = 8192;

CREATE table doc (
  -- an indexed HTML document
  id          INTEGER, -- rowid auto
  code        TEXT UNIQUE NOT NULL,   -- source filename without extension, unique for base
  filemtime   INTEGER NOT NULL, -- ! modified time
  filesize    INTEGER, -- ! filesize
  deps        TEXT,    -- ? generated files, useful for deletion
  title       TEXT NOT NULL,    -- ! html, for a structured short result
  byline      TEXT,    -- ? authors… text, searchable
  date        INTEGER, -- ? favorite date for chronological sort
  start       INTEGER, -- ? creation, start year, when chronological queries are relevant
  end         INTEGER, -- ? creation, end year, when chronological queries are relevant
  issued      INTEGER, -- ? publication year of the text
  bibl        TEXT,    -- ? publication year of the text
  source      TEXT,    -- ? URL of source file (ex: XML/TEI)
  editby      TEXT,    -- ? editors
  publisher   TEXT,    -- ? Name of the original publisher of the file in case of compilation
  identifier  TEXT,    -- ? URL of the orginal publication in case of compilation
  class       TEXT,    -- ? a classname
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX IF NOT EXISTS doc_code ON doc( code );
CREATE INDEX IF NOT EXISTS doc_byline_date ON doc( byline, date, code );
CREATE INDEX IF NOT EXISTS doc_date_byline ON doc( date, byline, code );

CREATE VIRTUAL TABLE search USING FTS3 (
  -- table of searchable articles, with same rowid as in article
  text        TEXT  -- exact text
);

CREATE TRIGGER IF NOT EXISTS docDel
  -- on book deletion, delete full text index
  BEFORE DELETE ON doc
  FOR EACH ROW BEGIN
    DELETE FROM search WHERE search.docid=OLD.id;
END;

  ";
  /** SQLite link, maybe useful outside */
  public $pdo;
  /** Stored queries */
  private $_q = array();
  /** Processeur xslt */
  private $_xslt;
  /** A logger, maybe a stream or a callable, used by $this->log() */
  private $_logger;
  /** Log level */
  public $loglevel = E_ALL;
  /** Shared list of code in a loop of files */
  private $_cods;
  /** valeur par défaut de la configuration */
  public $conf = array(
    "destdir" => "",
    "formats" => "site, epub, kindle",
    "cmdup" => "git pull 2>&1",
    "logger" => "php://output",
    "logfile" => null,
  );

  /**
   * Constructeur de la base
   */
  public function __construct( $conf )
  {
    $this->conf = array_merge( $this->conf, $conf );
    if ( !$this->conf['formats'] ) $this->conf['formats']=array_keys( self::$_formats );
    else if ( is_string( $this->conf['formats'] ) ) {
      $this->conf['formats'] = preg_split( "@[\s,;]+@", $this->conf['formats'] );
    }
    if ( !$this->conf['destdir'] );
    else if ( $this->conf['destdir'] == "." ) $this->conf['destdir']="";
    else $this->conf['destdir'] = rtrim($this->conf['destdir'], '\\/')."/";
    // verify output dirs for generated files
    foreach ( $this->conf['formats'] as $type ) {
      if ( !isset( self::$_formats[$type] ) ) {
        $this->log( E_USER_WARNING, $type." format non supporté" );
        continue;
      }
      $dir = $this->conf['destdir'].$type.'/';
      if ( !file_exists( $dir ) ) {
        if ( !@mkdir( $dir, 0775, true ) ) exit( $dir." impossible à créer.\n");
        @chmod( $dir, 0775 );  // let @, if www-data is not owner but allowed to write
      }
    }
    if ( is_string( $this->conf['logger'] ) ) $this->_logger = fopen( $this->conf['logger'], 'w');
    $this->_connect( $this->conf['sqlite'] );
    $this->_prepare();
  }
  /**
   * Connexion à la base
   */
  private function _connect( $sqlite )
  {
    $dsn = "sqlite:".$sqlite;
    // si la base n’existe pas, la créer
    if ( !file_exists($sqlite) ) {
      if ( !file_exists($dir = dirname($sqlite)) ) {
        mkdir( $dir, 0775, true );
        @chmod( $dir, 0775 );  // let @, if www-data is not owner but allowed to write
      }
      $this->pdo = new PDO( $dsn );
      $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
      @chmod( $sqlite, 0775 );
      $this->pdo->exec( self::$create );
    }
    else {
      $this->pdo = new PDO( $dsn );
      $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
    // table temporaire en mémoire
    $this->pdo->exec( "PRAGMA temp_store = 2;" );
  }
  /**
   * Prepare queries;
   */
  private function _prepare()
  {
    $this->_q['mtime'] = $this->pdo->prepare("SELECT filemtime FROM doc WHERE code = ?");
    $sql = "INSERT INTO doc (".implode( self::$coldoc, ", " ).") VALUES ( ?".str_repeat(", ?", count( self::$coldoc ) -1 )." );";
    $this->_q['record'] = $this->pdo->prepare($sql);
    $this->_q['search'] = $this->pdo->prepare( "INSERT INTO search ( docid, text ) VALUES ( ?, ? );" );
    $this->_q['del'] = $this->pdo->prepare("DELETE FROM doc WHERE code = ?");
  }
  /**
   * Efficient truncate table
   */
  public function clean()
  {
    // TODO, delete created folders
    $this->pdo->exec("DROP TABLE doc; DROP TABLE search; ");
    $this->pdo->exec(self::$create);
    $this->_prepare();
  }

  /**
   * Delete exports of a file
   */
  public function delete( $code, $destdir="" )
  {
    $echo = "";
    $this->_q['del']->execute( array( $code ) );
    foreach ( $this->conf['formats'] as $type ) {
      if ( !isset(self::$_formats[$type]) ) continue;
      $extension = self::$_formats[$type];
      $destfile = $destdir.$type.'/'.$code.$extension;
      if ( !file_exists( $destfile )) continue;
      unlink($destfile);
      $echo .= " ".basename( $destfile );
    }
    if ( $echo ) $this->log( E_USER_NOTICE, $echo );
  }

  /**
   * Produire les exports depuis le fichier XML
   */
  public function export( $teinte, $destdir="" )
  {
    $echo = "";
    foreach ( $this->conf['formats'] as $type ) {
      if ( !isset( self::$_formats[$type] ) ) {
        // already said upper
        // $this->log( E_USER_WARNING, $type." format non supporté" );
        continue;
      }
      $ext = self::$_formats[$type];
      $destfile = $destdir.$type.'/'.$teinte->filename().$ext;
      // delete destfile if exists
      if ( file_exists( $destfile ) ) unlink( $destfile );
      $echo .= " ".$type;
           if ( $type == 'html' ) $teinte->html( $destfile, 'http://oeuvres.github.io/Teinte/' );
      else if ( $type == 'article' ) $teinte->article( $destfile );
      else if ( $type == 'toc' ) $teinte->toc( $destfile );
      else if ( $type == 'markdown' ) $teinte->markdown( $destfile );
      else if ( $type == 'iramuteq' ) $teinte->iramuteq( $destfile );
      else if ( $type == 'docx' ) Toff_Tei2docx::docx( $teinte->file(), $destfile );
      else if ( $type == 'epub' ) {
        $livre = new Livrable_Tei2epub( $teinte->file(), $this->_logger );
        $livre->epub( $destfile );
      }
      // Attention, do kindle after epub
      else if ( $type == 'kindle' ) {
        $epubfile =  $destdir.'epub/'.$teinte->filename().".epub";
        // generated file
        $mobifile = $destdir.'epub/'.$teinte->filename().".mobi";
        $cmd = self::$_kindlegen.' '.$epubfile;
        $last = exec ($cmd, $output, $status);
        // error ?
        if (!file_exists( $mobifile )) {
          $this->log(E_USER_ERROR, "\n".$status."\n".htmlspecialchars( join( "\n", $output ) )."\n".$last."\n");
        }
        // move
        else {
          rename( $mobifile, $destfile );
        }
      }
    }
    if ($echo) $this->log( E_USER_NOTICE, $echo );
  }

  /**
   * Insert a record in the database about a Teinte document
   * Return rowid inserted
   */
  private function record( $teinte, $props=null )
  {
    $meta = $teinte->meta();
    // URLs with string replcaements
    if ( is_array ( $props ) ) {
      if ( isset( $props['source'] )  && strpos( $props['source'], '%s' ) !== false ) {
        $props['source'] = sprintf ( $props['source'], $meta['code'] );
      }
      if ( $props && isset( $props['identifier'] )  && strpos( $props['identifier'], '%s' ) !== false ) {
        $props['identifier'] = sprintf ( $props['identifier'], $meta['code'] );
      }
    }
    // space separated list of classnames
    if ( !isset( $meta['class'] ) ) $meta['class'] = "";
    $meta['class'] .= " ".basename( dirname( $teinte->file() ) );
    if ( isset( $props['set'] ) ) $meta['class'] .= " ".$props['set'];
    // del file, trigger should work for cascades
    $this->_q['del']->execute( array( $meta['code'] ) );

    $values = array_fill_keys( self::$coldoc, null );
    // replace in values, but do not add new keys from meta (intersect)
    $values = array_replace( $values, array_intersect_key( $meta, $values ) );
    // replace in values, but do not add keys, and change their order
    if ( is_array( $props ) ) $values = array_replace( $values, array_intersect_key( $props, $values ) );
    if ( !$values['title'] ) {
      $values['title'] = $values['code'];
      $this->log( E_USER_ERROR, $values['code']." <title> unfound" );
    }
    $values = array_values( $values );

    $this->_q['record']->execute( $values ); // no keys for values
    $lastid = $this->pdo->lastInsertId();
    $this->_q['search']->execute( array( $lastid, $teinte->ft() ) );
    return $lastid;
  }
  /**
   * Explore conf as sets
   */
  public function sets()
  {
    if ( !isset($this->conf['sets']) ) continue;
    // for deletions, store the codes of existingfiles
    $this->_cods = array();
    $oldlog = $this->loglevel;
    $this->loglevel = $this->loglevel|E_USER_NOTICE;
    foreach ( $this->conf['sets'] as $set => $props ) {
      $props['set'] = $set;
      // resolve relative path ?
      foreach( glob( $props['glob'] ) as $srcfile) {
        $this->_file( $srcfile, null, $props );
      }
    }
    $this->loglevel = $oldlog;
    // delete record of delete files
    $this->_post();
  }

  /**
   * Explore some folders
   */
  public function glob( $glob=null, $force=false, $props=null )
  {
    $this->_cods = array(); // store the codes of each file in the srclist to delete some files
    if ( !$glob ) $glob = $this->conf['srcglob'];
    $oldlog = $this->loglevel;
    $this->loglevel = $this->loglevel|E_USER_NOTICE;
    if ( !is_array( $glob ) ) $glob = array( $glob );
    foreach ( $glob as $path ) {
      foreach( glob( $path ) as $srcfile) {
        // echo "\n".$srcfile." ".$force;
        $this->_file( $srcfile, $force, $props );
      }
    }
    $this->_post();
    $this->loglevel = $oldlog;
  }

  private function _file( $srcfile, $force=false, $props=array() )
  {
    $code = pathinfo( $srcfile, PATHINFO_FILENAME );
    $this->_cods[] = $code;
    if ( !file_exists( $srcfile ) ) {
      $this->log( E_USER_NOTICE, $srcfile." fichier non trouvé (".$srclist." l. ".$line.")" );
      return false;
    }
    $srcmtime = filemtime( $srcfile );
    $this->_q['mtime']->execute( array( $code) );
    list( $basemtime ) = $this->_q['mtime']->fetch();
    if ( $basemtime < $srcmtime ) $force = true; // source is newer than record, work
    // is there missing generated files ?
    foreach ( $this->conf['formats'] as $type ) {
      if ( !isset(self::$_formats[$type]) ) continue;
      // toc mays be null
      if ( $type == "toc" ) continue;
      $extension = self::$_formats[$type];
      $destfile = $this->conf["destdir"].$type.'/'.$code.$extension;
      if ( !file_exists( $destfile )) {
        $force = true;
      }
    }
    if ( !$force ) return $force;
    $this->log( E_USER_NOTICE, $srcfile );
    // here possible XML error
    try  {
      $teinte = new Teinte_Doc( $srcfile );
    }
    catch ( Exception $e ) {
      $this->log( E_USER_ERROR, $srcfile." XML mal formé" );
      return false;
    }
    $this->export( $teinte, $this->conf["destdir"] );
    // record in base after generation of file
    $this->record( $teinte, $props );
    flush();
  }

  private function _post()
  {
    // Check for deteleted files
    $q = $this->pdo->query("SELECT * FROM doc WHERE code NOT IN ( '".implode( "', '", $this->_cods )."' )");
    $del = array();
    foreach (  $q as $row ) {
      $this->log( E_USER_NOTICE, "\nSUPPRESSION ".$row['code'] );
      $this->delete( $row['code'], $this->conf["destdir"] );
    }
    // $this->pdo->exec( "VACUUM" );
    $this->pdo->exec( "INSERT INTO  search(search) VALUES( 'optimize' ); -- optimize fulltext index " );
  }

  /**
   * Build a site from a list of TEI files
   */
  public function csv( $srclist, $sep=";" )
  {
    $oldlog = $this->loglevel;
    $this->loglevel = $this->loglevel|E_USER_NOTICE;
    $this->log( E_USER_NOTICE, $srclist." lecture de la liste de fichiers à publier" );
    $this->_cods = array(); // store the codes of each file in the srclist to delete some files
    $handle = fopen( $srclist, "r" );
    $keys = fgetcsv( $handle, 0, $sep ); // first line, column names
    $srcdir = dirname( $srclist );
    if ( $srcdir == "." ) $srcdir = "";
    else $srcdir .= "/";
    $line = 1;
    // no transaction for XML errors
    // $this->pdo->beginTransaction();
    while ( ($values = fgetcsv( $handle, 0, $sep )) !== FALSE) {
      $line++;
      if ( count( $values ) < 1 ) continue;
      if ( !$values[0] ) continue;
      if ( substr ( trim( $values[0] ), 0) == '#'  ) continue;
      if ( count( $keys ) > count( $values ) ) // less values than keys, fill for a good combine
        $values = array_merge( $values, array_fill( 0, count( $keys ) - count( $values ), null ) ) ;
      $row = array_combine($keys, $values);
      $srcfile = $srcdir.current($row);
      $this->_file( $srcfile );
    }
    fclose($handle);
    // $this->pdo->commit();
    $this->loglevel = $oldlog;
  }

  /**
   * Sortir le catalogue en table html
   */
  public function table($cols=array("no", "publisher", "creator", "date", "title", "downloads"))
  {
    $labels = array(
      "no"=>"N°",
      "publisher" => "Éditeur",
      "creator" => "Auteur",
      "date" => "Date",
      "title" => "Titre",
      "tei" => "Titre",
      "downloads" => "Téléchargements",
      "relation" => "Téléchargements",
    );
    echo '<table class="sortable">'."\n  <tr>\n";
    foreach ( $cols as $code ) {
      echo '    <th>'.$labels[$code]."</th>\n";
    }
    echo "  </tr>\n";
    $i = 1;
    foreach ($this->pdo->query("SELECT * FROM doc ORDER BY code") as $oeuvre) {
      echo "  <tr>\n";
      foreach ( $cols as $code ) {
        if (!isset($labels[$code])) continue;
        echo "    <td>";
        if ("no" == $code) {
          echo $i;
        }
        else if ("publisher" == $code) {
          if ($oeuvre['identifier']) echo '<a href="'.$oeuvre['identifier'].'">'.$oeuvre['publisher']."</a>";
          else echo $oeuvre['publisher'];
        }
        else if("creator" == $code || "author" == $code) {
          echo $oeuvre['byline'];
        }
        else if("date" == $code || "year" == $code) {
          echo $oeuvre['date'];
        }
        else if("title" == $code) {
          if ($oeuvre['identifier']) echo '<a href="'.$oeuvre['identifier'].'">'.$oeuvre['title']."</a>";
          else echo $oeuvre['title'];
        }
        else if("tei" == $code) {
          echo '<a href="'.$oeuvre['code'].'.xml">'.$oeuvre['title'].'</a>';
        }
        else if("relation" == $code || "downloads" == $code) {
          if ($oeuvre['source']) echo '<a href="'.$oeuvre['source'].'">TEI</a>';
          $sep = ", ";
          foreach ( $this->conf['formats'] as $type ) {
            echo $sep.'<a href="'.$type.'/'.$oeuvre['code'].self::$_formats[$type].'">'.$type.'</a>';
          }
        }
        echo "</td>\n";
      }
      echo "  </tr>\n";
      $i++;
    }
    echo "\n</table>\n";
  }



  /**
   * Check dependencies
   */
  static function deps()
  {
    if(self::$_deps) return;
    // Deps
    $inc = dirname(__FILE__).'/../Livrable/Tei2epub.php';
    if (!file_exists($inc)) {
      echo "Impossible de trouver ".realpath(dirname(__FILE__).'/../')."/Livrable/
    Vous pouvez le télécharger sur https://github.com/oeuvres/Livrable\n";
      exit();
    }
    else {
      include_once($inc);
    }
    $inc = dirname(__FILE__).'/../Livrable/kindlegen';
    if (!file_exists($inc)) $inc = dirname(__FILE__).'/../Livrable/kindlegen.exe';
    if (!file_exists($inc)) {
      echo "Si vous souhaitez la conversion de vos epubs pour Kindle, il faut télécharger chez Amazon
      le programme kindlegen pour votre système http://www.amazon.fr/gp/feature.html?ie=UTF8&docId=1000570853
      à placer dans le dossier Livrable : ".dirname($inc);
    }
    if (file_exists($inc)) self::$_kindlegen = realpath($inc);


    $inc = dirname(__FILE__).'/Doc.php';
    if (!file_exists($inc)) {
      echo "Impossible de trouver ".dirname(__FILE__)."/Teinte/Doc.php
    Vous pouvez le télécharger sur https://github.com/oeuvres/Teinte\n";
      exit();
    }
    else {
      include_once($inc);
    }
    /*
    $inc = dirname(__FILE__).'/../Toff/Tei2docx.php';
    if (!file_exists($inc)) {
      echo "Impossible de trouver ".realpath(dirname(__FILE__).'/../')."/Toff/
    Vous pouvez le télécharger sur https://github.com/oeuvres/Toff\n";
      exit();
    }
    else {
      include_once($inc);
    }
    */
    self::$_deps=true;
  }

  /**
   * Custom error handler
   * May be used for xsl:message coming from transform()
   * To avoid Apache time limit, php could output some bytes during long transformations
   */
  function log( $errno, $errstr, $errfile=null, $errline=null, $errcontext=null )
  {
    if ( $this->conf['logfile'] ) {
      $line = date( "Y-m-d H:i:s   " );
      if ( E_ERROR == $errno || E_USER_ERROR == $errno ) $line .= "ERROR ";
      else if ( E_WARNING == $errno || E_USER_WARNING == $errno ) $line .= "WARNING ";
      $line .= $errstr;
      $line .= "\n";
      error_log( $line, 3, $this->conf['logfile'] );
    }
    if ( !$this->loglevel & $errno ) return false;
    $errstr=preg_replace("/XSLTProcessor::transform[^:]*:/", "", $errstr, -1, $count);
    /* ?
    if ( $count ) { // is an XSLT error or an XSLT message, reformat here
      if ( strpos($errstr, 'error') !== false ) return false;
      else if ( $errno == E_WARNING ) $errno = E_USER_WARNING;
    }
    */
    if ( !$this->_logger );
    else if ( is_resource( $this->_logger ) ) fwrite( $this->_logger, $errstr."\n");
    else if ( is_string( $this->_logger ) && function_exists( $this->_logger ) ) call_user_func( $this->_logger, $errstr );
  }

  /**
   * Check epub
   */
  static function epubcheck( $glob )
  {
    echo "epubcheck epub/*.epub\n";
    foreach(glob($glob) as $file) {
      echo $file;
      // validation
      $cmd = "java -jar ".dirname(__FILE__)."/epubcheck/epubcheck.jar ".$file;
      $last = exec ($cmd, $output, $status);
      echo ' '.$status."\n";
      if ($status) rename($file, dirname($file).'/_'.basename($file));
    }
  }

  /**
   * Command line API
   */
  static function cli()
  {
    $timeStart = microtime(true);
    $usage = "\nusage    : php -f ".basename(__FILE__).' conf.php
usage    : php -f '.basename(__FILE__).' base.sqlite action "dir/*.xml"'."\n\n";
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if ( count($_SERVER['argv']) == 1 ) {
      $path = $_SERVER['argv'][0];
      if ( !file_exists( $path ) ) exit( $path+" — ce fichier n’existe pas." );
      $conf = include( $path );
      $build = new Teinte_Build( $conf );
      $build->glob();
      exit();
    }


    if (count($_SERVER['argv']) < 2) exit($usage);
    $sqlite = array_shift($_SERVER['argv']);
    $action = array_shift($_SERVER['argv']);
    $base = new Teinte_Build($sqlite, STDERR);
    while($glob = array_shift($_SERVER['argv']) ) {
      foreach(glob($glob) as $srcfile) {
        fwrite (STDERR, $srcfile);
        if ("insert" == $action) {
          $base->insert( $srcfile );
        }

        // $base->add($file, $setcode);
        fwrite(STDERR, "\nFINI\n");
      }
    }
    /*
    $ext = pathinfo ($file, PATHINFO_EXTENSION);
    // seems a list of uri
    if ($ext == 'csv' || $ext == "txt") { // ? }
    */
  }
}
?>
