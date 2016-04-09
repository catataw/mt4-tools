<?php
/**
 * Object-Wrapper f�r eine MT4-History-Datei ("*.hst")
 */
class HistoryFile extends Object {

   protected /*int          */ $hFile;                            // File-Handle einer ge�ffneten Datei
   protected /*string       */ $fileName;                         // einfacher Dateiname
   protected /*string       */ $serverName;                       // einfacher Servername
   protected /*string       */ $serverDirectory;                  // vollst�ndiger Name des Serververzeichnisses
   protected /*bool         */ $closed = false;                   // ob die Instanz geschlossen und seine Resourcen freigegeben sind

   protected /*HistoryHeader*/ $hstHeader;
   protected /*int          */ $lastM1DataTime = 0;               // OpenTime der letzten eingetroffenen M1-Daten
   protected /*int          */ $barSize        = 0;               // Gr��e einer Bar entsprechend dem Datenformat
   protected /*string       */ $barPackFormat;                    // Formatstring f�r pack()
   protected /*string       */ $barUnpackFormat;                  // Formatstring f�r unpack()
   protected /*MYFX_BAR[]   */ $barBuffer     = array();
   protected /*int          */ $barBufferSize = 10000;            // Default-Gr��e des Buffers f�r ungespeicherte Bars

   // Metadaten: gespeichert
   protected /*int          */ $stored_bars           =  0;       // Anzahl der gespeicherten Bars der Datei
   protected /*int          */ $stored_from_offset    = -1;       // Offset der ersten gespeicherten Bar der Datei
   protected /*int          */ $stored_from_openTime  =  0;       // OpenTime der ersten gespeicherten Bar der Datei
   protected /*int          */ $stored_from_closeTime =  0;       // CloseTime der ersten gespeicherten Bar der Datei
   protected /*int          */ $stored_to_offset      = -1;       // Offset der letzten gespeicherten Bar der Datei
   protected /*int          */ $stored_to_openTime    =  0;       // OpenTime der letzten gespeicherten Bar der Datei
   protected /*int          */ $stored_to_closeTime   =  0;       // CloseTime der letzten gespeicherten Bar der Datei
   protected /*int          */ $stored_lastSyncTime   =  0;       // Zeitpunkt, bis zu dem die gespeicherten Daten der Datei synchronisiert wurden

   // Metadaten: gespeichert + ungespeichert
   protected /*int          */ $full_bars             =  0;       // Anzahl der Bars der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_from_offset      = -1;       // Offset der ersten Bar der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_from_openTime    =  0;       // OpenTime der ersten Bar der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_from_closeTime   =  0;       // CloseTime der ersten Bar der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_to_offset        = -1;       // Offset der letzten Bar der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_to_openTime      =  0;       // OpenTime der letzten Bar der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_to_closeTime     =  0;       // CloseTime der letzten Bar der Datei inkl. ungespeicherter Daten im Schreibpuffer
   protected /*int          */ $full_lastSyncTime     =  0;       // Zeitpunkt, bis zu dem die kompletten Daten der Datei synchronisiert wurden


   // Getter
   public function getFileName()        { return $this->fileName;                   }
   public function getServerName()      { return $this->serverName;                 }
   public function getServerDirectory() { return $this->serverDirectory;            }
   public function isClosed()           { return (bool)$this->closed;               }

   public function getVersion()         { return $this->hstHeader->getFormat();     }
   public function getSymbol()          { return $this->hstHeader->getSymbol();     }
   public function getTimeframe()       { return $this->hstHeader->getPeriod();     }
   public function getPeriod()          { return $this->hstHeader->getPeriod();     }  // Alias
   public function getDigits()          { return $this->hstHeader->getDigits();     }
   public function getSyncMarker()      { return $this->hstHeader->getSyncMarker(); }
   public function getLastSyncTime()    { return $this->full_lastSyncTime;          }


   /**
    * �berladener Constructor.
    *
    * Signaturen:
    * -----------
    * new HistoryFile($symbol, $timeframe, $digits, $format, $serverDirectory)
    * new HistoryFile($fileName)
    */
   public function __construct($arg1=null, $arg2=null, $arg3=null, $arg4=null, $arg5=null) {
      $argc = func_num_args();
      if      ($argc == 5) $this->__construct_1($arg1, $arg2, $arg3, $arg4, $arg5);
      else if ($argc == 1) $this->__construct_2($arg1);
      else throw new plInvalidArgumentException('Invalid number of arguments: '.$argc);
   }


   /**
    * Constructor 1
    *
    * Erzeugt eine neue Instanz und setzt eine existierende Datei zur�ck. Vorhandene Daten werden dabei gel�scht.
    *
    * @param  string $symbol          - Symbol
    * @param  int    $timeframe       - Timeframe
    * @param  int    $digits          - Digits
    * @param  int    $format          - Speicherformat der Datenreihe:
    *                                   � 400 - MetaTrader <= Build 509
    *                                   � 401 - MetaTrader  > Build 509
    * @param  string $serverDirectory - Speicherort der Datei
    */
   private function __construct_1($symbol, $timeframe, $digits, $format, $serverDirectory) {
      if (!is_string($symbol))                      throw new IllegalTypeException('Illegal type of parameter $symbol: '.getType($symbol));
      if (!strLen($symbol))                         throw new plInvalidArgumentException('Invalid parameter $symbol: ""');
      if (strLen($symbol) > MT4::MAX_SYMBOL_LENGTH) throw new plInvalidArgumentException('Invalid parameter $symbol: "'.$symbol.'" (max '.MT4::MAX_SYMBOL_LENGTH.' characters)');
      if (!is_int($timeframe))                      throw new IllegalTypeException('Illegal type of parameter $timeframe: '.getType($timeframe));
      if (!MT4::isStdTimeframe($timeframe))         throw new plInvalidArgumentException('Invalid parameter $timeframe: '.$timeframe.' (not a MetaTrader standard timeframe)');
      if (!is_string($serverDirectory))             throw new IllegalTypeException('Illegal type of parameter $serverDirectory: '.getType($serverDirectory));
      if (!is_dir($serverDirectory))                throw new plInvalidArgumentException('Directory "'.$serverDirectory.'" not found');

      $this->hstHeader       = new HistoryHeader($format, null, $symbol, $timeframe, $digits, null, null);
      $this->serverDirectory = realPath($serverDirectory);
      $this->serverName      = baseName($this->serverDirectory);
      $this->fileName        = $symbol.$timeframe.'.hst';

      // HistoryFile erzeugen bzw. zur�cksetzen und Header neuschreiben
      mkDirWritable($this->serverDirectory);
      $fileName    = $this->serverDirectory.'/'.$this->fileName;
      $this->hFile = fOpen($fileName, 'wb');                      // FILE_WRITE
      $this->writeHistoryHeader();

      // Metadaten einlesen und initialisieren
      $this->initMetaData();
   }


   /**
    * Constructor 2
    *
    * Erzeugt eine neue Instanz anhand einer existierenden Datei. Vorhandene Daten werden nicht gel�scht.
    *
    * @param  string $fileName - Name einer History-Datei
    */
   private function __construct_2($fileName) {
      if (!is_string($fileName)) throw new IllegalTypeException('Illegal type of parameter $fileName: '.getType($fileName));
      if (!is_file($fileName))   throw new FileNotFoundException('Invalid parameter $fileName: "'.$fileName.'" (file not found)');

      // Verzeichnis- und Dateinamen speichern
      $realName              = realPath($fileName);
      $this->fileName        = baseName($realName);
      $this->serverDirectory = dirname ($realName);
      $this->serverName      = baseName($this->serverDirectory);

      // Dateigr��e validieren
      $fileSize = fileSize($fileName);
      if ($fileSize < HistoryHeader::SIZE) throw new MetaTraderException('filesize.insufficient: Invalid or unsupported format of "'.$fileName.'": fileSize='.$fileSize.' (minFileSize='.HistoryHeader::SIZE.')');

      // Datei �ffnen, Header einlesen und validieren
      $this->hFile     = fOpen($fileName, 'r+b');               // FILE_READ|FILE_WRITE
      $this->hstHeader = new HistoryHeader(fRead($this->hFile, HistoryHeader::SIZE));

      if (!strCompareI($this->fileName, $this->getSymbol().$this->getTimeframe().'.hst')) throw new MetaTraderException('filename.mis-match: File name/symbol mis-match of "'.$fileName.'": header="'.$this->getSymbol().','.MyFX::periodDescription($this->getTimeframe()).'"');
      $barSize = $this->getVersion()==400 ? MT4::HISTORY_BAR_400_SIZE : MT4::HISTORY_BAR_401_SIZE;
      if ($trailing=($fileSize-HistoryHeader::SIZE) % $barSize)                           throw new MetaTraderException('filesize.trailing: Corrupted file "'.$fileName.'": '.$trailing.' trailing bytes');

      // Metadaten einlesen und initialisieren
      $this->initMetaData();
   }


   /**
    * Liest die Metadaten der Datei aus und initialisiert die lokalen Variablen. Aufruf nur aus einem Constructor.
    */
   private function initMetaData() {
      $this->barSize         = $this->getVersion()==400 ? MT4::HISTORY_BAR_400_SIZE : MT4::HISTORY_BAR_401_SIZE;
      $this->barPackFormat   = MT4::BAR_getPackFormat($this->getVersion());
      $this->barUnpackFormat = MT4::BAR_getUnpackFormat($this->getVersion());

      $fileSize = fileSize($this->serverDirectory.'/'.$this->fileName);
      if ($fileSize > HistoryHeader::SIZE) {
         $bars    = ($fileSize-HistoryHeader::SIZE) / $this->barSize;
         $barFrom = $barTo = unpack($this->barUnpackFormat, fRead($this->hFile, $this->barSize));
         if ($bars > 1) {
            fSeek($this->hFile, HistoryHeader::SIZE + ($bars-1)*$this->barSize);
            $barTo = unpack($this->barUnpackFormat, fRead($this->hFile, $this->barSize));
         }
         $period = $this->getPeriod();

         $from_offset    = 0;
         $from_openTime  = $barFrom['time'];
         $from_closeTime = MyFX::periodCloseTime($from_openTime,  $period);

         $to_offset      = $bars-1;
         $to_openTime    = $barTo['time'];
         $to_closeTime   = MyFX::periodCloseTime($to_openTime,  $period);

         // Metadaten: gespeicherte Bars
         $this->stored_bars           = $bars;
         $this->stored_from_offset    = $from_offset;
         $this->stored_from_openTime  = $from_openTime;
         $this->stored_from_closeTime = $from_closeTime;
         $this->stored_to_offset      = $to_offset;
         $this->stored_to_openTime    = $to_openTime;
         $this->stored_to_closeTime   = $to_closeTime;
         $this->stored_lastSyncTime   = $this->hstHeader->getLastSyncTime();

         // Metadaten: gespeicherte + gepufferte Bars
         $this->full_bars             = $this->stored_bars;
         $this->full_from_offset      = $this->stored_from_offset;
         $this->full_from_openTime    = $this->stored_from_openTime;
         $this->full_from_closeTime   = $this->stored_from_closeTime;
         $this->full_to_offset        = $this->stored_to_offset;
         $this->full_to_openTime      = $this->stored_to_openTime;
         $this->full_to_closeTime     = $this->stored_to_closeTime;
         $this->full_lastSyncTime     = $this->stored_lastSyncTime;

         $this->lastM1DataTime        = max($to_openTime, $this->stored_lastSyncTime-1*MINUTE);    // die letzte Bar kann noch offen sein
      }
   }


   /**
    * Destructor
    *
    * Sorgt bei Zerst�rung des Objekts daf�r, da� der Schreibbuffer einer offenen Historydatei geleert und die Datei geschlossen wird.
    */
   public function __destruct() {
      // Ein Destructor darf w�hrend des Shutdowns keine Exception werfen.
      try {
         !$this->isClosed() && $this->close();
      }
      catch (Exception $ex) {
         Logger::handleException($ex, $inShutdownOnly=true);
         throw $ex;
      }
   }


   /**
    * Schlie�t dieses HistoryFile. Gibt die Resourcen dieser Instanz frei. Nach dem Aufruf kann die Instanz nicht mehr verwendet werden.
    *
    * @return bool - Erfolgsstatus; FALSE, wenn die Instanz bereits geschlossen war
    */
   public function close() {
      if ($this->isClosed())
         return false;

      // Barbuffer leeren
      if ($this->barBuffer) {
         $this->flush();
      }

      // Datei schlie�en
      if (is_resource($this->hFile)) {
         $hTmp=$this->hFile; $this->hFile=null;
         fClose($hTmp);
      }
      return $this->closed=true;
   }


   /**
    * Setzt die Buffergr��e f�r vor dem Schreiben zwischenzuspeichernde Bars dieser Instanz.
    *
    * @param  int $size - Buffergr��e
    */
   public function setBarBufferSize($size) {
      if ($this->closed)  throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!is_int($size)) throw new IllegalTypeException('Illegal type of parameter $size: '.getType($size));
      if ($size < 0)      throw new plInvalidArgumentException('Invalid parameter $size: '.$size);

      $this->barBufferSize = $size;
   }


   /**
    * Gibt die Bar am angegebenen Offset der Historydatei zur�ck.
    *
    * @param  int $offset
    *
    * @return array - � MYFX_BAR,    wenn die Bar im Schreibbuffer liegt
    *                 � HISTORY_BAR, wenn die Bar aus der Datei gelesen wurde
    *                 � NULL,        wenn keine solche Bar existiert (Offset ist gr��er als die Anzahl der Bars der Datei)
    *
    * @see    HistoryFile::getMyfxBar(), HistoryFile::getHistoryBar()
    */
   public function getBar($offset) {
      if (!is_int($offset)) throw new IllegalTypeException('Illegal type of parameter $offset: '.getType($offset));
      if ($offset < 0)      throw new plInvalidArgumentException('Invalid parameter $offset: '.$offset);

      if ($offset >= $this->full_bars)                                           // bar[$offset] existiert nicht
         return null;

      if ($offset > $this->stored_to_offset)                                     // bar[$offset] liegt in buffered Bars (MYFX_BAR)
         return $this->barBuffer[$offset-$this->stored_to_offset-1];

      fSeek($this->hFile, HistoryHeader::SIZE + $offset*$this->barSize);         // bar[$offset] liegt in stored Bars (HISTORY_BAR)
      return unpack($this->barUnpackFormat, fRead($this->hFile, $this->barSize));
   }


   /**
    * Gibt den Offset eines Zeitpunktes innerhalb dieser Historydatei zur�ck. Dies ist die Position (Index), an der eine Bar
    * mit der angegebenen OpenTime in dieser Historydatei einsortiert werden w�rde.
    *
    * @param  int $time - Zeitpunkt
    *
    * @return int - Offset oder -1, wenn der Zeitpunkt j�nger als die j�ngste Bar ist. Zum Schreiben einer Bar mit dieser
    *               Zeit mu� die Datei vergr��ert werden.
    */
   public function findTimeOffset($time) {
      if (!is_int($time)) throw new IllegalTypeException('Illegal type of parameter $time: '.getType($time));

      $size    = $this->full_bars; if (!$size)                 return -1;
      $iFrom   = 0;
      $iTo     = $size-1; if ($this->full_to_openTime < $time) return -1;
      $barFrom = array('time'=> $this->full_from_openTime);
      $barTo   = array('time'=> $this->full_to_openTime);
      $i       = -1;

      while (true) {                                                       // Zeitfenster von Beginn- und Endbar rekursiv bis zum
         if ($barFrom['time'] >= $time) {                                  // gesuchten Zeitpunkt verkleinern
            $i = $iFrom;
            break;
         }
         if ($barTo['time']==$time || $size==2) {
            $i = $iTo;
            break;
         }

         $midSize = (int) ceil($size/2);                                   // Fenster halbieren
         $iMid    = $iFrom + $midSize - 1;
         $barMid  = $this->getBar($iMid);

         if ($barMid['time'] <= $time) { $barFrom = $barMid; $iFrom = $iMid; }
         else                          { $barTo   = $barMid; $iTo   = $iMid; }
         $size = $iTo - $iFrom + 1;
      }
      return $i;
   }


   /**
    * Gibt den Offset der Bar dieser Historydatei zur�ck, die den angegebenen Zeitpunkt exakt abdeckt.
    *
    * @param  int $time - Zeitpunkt
    *
    * @return int - Offset oder -1, wenn keine solche Bar existiert
    */
   public function findBarOffset($time) {
      if (!is_int($time)) throw new IllegalTypeException('Illegal type of parameter $time: '.getType($time));

      $size = sizeOf($this->full_bars);
      if (!$size)
         return -1;

      $offset = $this->findTimeOffset($time);

      if ($offset < 0) {                                                         // Zeitpunkt liegt nach der j�ngsten bar[openTime]
         $closeTime = $this->full_to_closeTime;
         if ($time < $closeTime)                                                 // Zeitpunkt liegt innerhalb der j�ngsten Bar
            return $size-1;
         return -1;
      }

      if ($offset == 0) {
         if ($this->full_from_openTime == $time)                                 // Zeitpunkt liegt exakt auf der �ltesten Bar
            return 0;
         return -1;                                                              // Zeitpunkt ist �lter die �lteste Bar
      }

      $bar = $this->getBar($offset);
      if ($bar['time'] == $time)                                                 // Zeitpunkt liegt exakt auf der jeweiligen Bar
         return $offset;
      $offset--;

      $bar       = $this->getBar($offset);
      $closeTime = self::periodCloseTime($bar['time'], $this->getPeriod());

      if ($time < $closeTime)                                                    // Zeitpunkt liegt in der vorhergehenden Bar
         return $offset;
      return -1;                                                                 // Zeitpunkt liegt nicht in der vorhergehenden Bar,
   }                                                                             // also L�cke zwischen der vorhergehenden und der
                                                                                 // folgenden Bar

   /**
    * Gibt den Offset der Bar dieser Historydatei zur�ck, die den angegebenen Zeitpunkt abdeckt. Existiert keine solche Bar,
    * wird der Offset der letzten vorhergehenden Bar zur�ckgegeben.
    *
    * @param  int $time - Zeitpunkt
    *
    * @return int - Offset oder -1, wenn keine solche Bar existiert (der Zeitpunkt ist �lter als die �lteste Bar)
    */
   public function findBarOffsetPrevious($time) {
      if (!is_int($time)) throw new IllegalTypeException('Illegal type of parameter $time: '.getType($time));

      $size = $this->full_bars;
      if (!$size)
         return -1;

      $offset = $this->findTimeOffset($time);
      if ($offset < 0)                                                           // Zeitpunkt liegt nach der j�ngsten bar[openTime]
         return $size-1;

      $bar = $this->getBar($offset);

      if ($bar['time'] == $time)                                                 // Zeitpunkt liegt exakt auf der jeweiligen Bar
         return $offset;
      return $offset - 1;                                                        // Zeitpunkt ist �lter als die Bar desselben Offsets
   }


   /**
    * Gibt den Offset der Bar dieser Historydatei zur�ck, die den angegebenen Zeitpunkt abdeckt. Existiert keine solche Bar,
    * wird der Offset der n�chstfolgenden Bar zur�ckgegeben.
    *
    * @param  int $time - Zeitpunkt
    *
    * @return int - Offset oder -1, wenn keine solche Bar existiert (der Zeitpunkt ist j�nger als das Ende der j�ngsten Bar)
    */
   public function findBarOffsetNext($time) {
      if (!is_int($time)) throw new IllegalTypeException('Illegal type of parameter $time: '.getType($time));

      $size = $this->full_bars;
      if (!$size)
         return -1;

      $offset = $this->findTimeOffset($time);

      if ($offset < 0) {                                                         // Zeitpunkt liegt nach der j�ngsten bar[openTime]
         $closeTime = $this->full_to_closeTime;
         return ($closeTime > $time) ? $size-1 : -1;
      }
      if ($offset == 0)                                                          // Zeitpunkt liegt vor oder exakt auf der ersten Bar
         return 0;

      $bar = $this->getBar($offset);
      if ($bar['time'] == $time)                                                 // Zeitpunkt stimmt mit bar[openTime] �berein
         return $offset;

      $offset--;                                                                 // Zeitpunkt liegt in der vorherigen oder zwischen der
      $bar = $this->getBar($offset);                                             // vorherigen und der TimeOffset-Bar

      $closeTime = MyFX::periodCloseTime($bar['time'], $this->getPeriod());
      if ($closeTime > $time)                                                    // Zeitpunkt liegt innerhalb dieser vorherigen Bar
         return $offset;
      return ($offset+1 < $size) ? $offset+1 : -1;                               // Zeitpunkt liegt nach bar[closeTime], also L�cke...
   }                                                                             // zwischen der vorherigen und der folgenden Bar


   /**
    * Entfernt einen Teil der Historydatei und ersetzt ihn mit den �bergebenen Bardaten. Die Gr��e der Datei wird
    * entsprechend angepa�t.
    *
    * @param  int        $offset - Offset, ab dem Bars entfernt werden
    * @param  int        $length - Anzahl der zu entfernenden Bars
    * @param  MYFX_BAR[] $bars   - an Stelle der entfernten Bars einzuf�gende Bars (default: keine)
    */
   public function splice($offset, $length=null, array $bars=null) {
      throw new UnimplementedFeatureException(__METHOD__.'()');
   }


   /**
    * Synchronisiert die Historydatei dieser Instanz mit den �bergebenen Daten. Vorhandene Bars, die nach dem letzten
    * Synchronisationszeitpunkt der Datei hinzugef�gt wurden und sich mit den �bergebenen Daten �berschneiden, werden
    * ersetzt. Vorhandene Bars, die sich mit den �bergebenen Daten nicht �berschneiden, bleiben unver�ndert.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1 (werden automatisch in die Periode der Historydatei konvertiert)
    */
   public function synchronize(array $bars) {
      switch ($this->getPeriod()) {
         case PERIOD_M1:  $this->synchronizeM1 ($bars); break;
         case PERIOD_M5:  $this->synchronizeM5 ($bars); break;
         case PERIOD_M15: $this->synchronizeM15($bars); break;
         case PERIOD_M30: $this->synchronizeM30($bars); break;
         case PERIOD_H1:  $this->synchronizeH1 ($bars); break;
         case PERIOD_H4:  $this->synchronizeH4 ($bars); break;
         case PERIOD_D1:  $this->synchronizeD1 ($bars); break;
         case PERIOD_W1:  $this->synchronizeW1 ($bars); break;
         case PERIOD_MN1: $this->synchronizeMN1($bars); break;
         default:
            throw new plRuntimeException('Unsupported timeframe $this->period='.$this->getPeriod());
      }
   }


   /**
    * Synchronisiert die M1-History dieser Instanz.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function synchronizeM1(array $bars) {
      if ($this->closed) throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return false;

      // Offset der Bar, die den Zeitpunkt abdeckt, ermitteln
      $lastSyncTime = $this->full_lastSyncTime;
      $offset       = MyFX::findBarOffsetNext($bars, PERIOD_M1, $lastSyncTime);

      // Bars vor Offset verwerfen
      if ($offset == -1)                                                      // alle Bars liegen vor $lastSyncTime
         return;
      $bars = array_slice($bars, $offset);
      $size = sizeof($bars);

      // History-Offsets f�r die verbliebene Bar-Range ermitteln
      $hstOffsetFrom = $this->findBarOffsetNext($bars[0]['time']);
      if ($hstOffsetFrom == -1) {                                             // Zeitpunkt ist j�nger als die j�ngste Bar
         $this->appendBars($bars);
      }
      else {
         // History-Range mit Bar-Range ersetzen
         $hstOffsetTo = $this->findBarOffsetPrevious($bars[$size-1]['time']);
         $length      = $hstOffsetTo - $hstOffsetFrom + 1;

         //$this->showMetaData();
         echoPre('inserting '.$size.' bars from '.gmDate('d-M-Y H:i:s', $bars[0]['time']).' to '.gmDate('d-M-Y H:i:s', $bars[$size-1]['time']));
         echoPre('replacing '.($hstOffsetTo - $hstOffsetFrom + 1).' history bars from offset '.$hstOffsetFrom.' to '.$hstOffsetTo);

         $this->splice($hstOffsetFrom, $length, $bars);
      }
   }


   /**
    * F�gt der Historydatei dieser Instanz Bardaten hinzu. Die Daten werden ans Ende der Zeitreihe angef�gt.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   public function appendBars(array $bars) {
      switch ($this->getPeriod()) {
         case PERIOD_M1:  $this->appendToM1 ($bars); break;
         case PERIOD_M5:  $this->appendToM5 ($bars); break;
         case PERIOD_M15: $this->appendToM15($bars); break;
         case PERIOD_M30: $this->appendToM30($bars); break;
         case PERIOD_H1:  $this->appendToH1 ($bars); break;
         case PERIOD_H4:  $this->appendToH4 ($bars); break;
         case PERIOD_D1:  $this->appendToD1 ($bars); break;
         case PERIOD_W1:  $this->appendToW1 ($bars); break;
         case PERIOD_MN1: $this->appendToMN1($bars); break;
         default:
            throw new plRuntimeException('Unsupported timeframe $this->period='.$this->getPeriod());
      }
   }


   /**
    * F�gt der M1-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToM1(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $this->barBuffer = array_merge($this->barBuffer, $bars);
      $bufferSize      = sizeOf($this->barBuffer);

      if (!$this->full_bars) {                                          // History ist noch leer
         $this->full_from_offset    = 0;
         $this->full_from_openTime  = $this->barBuffer[0]['time'];
         $this->full_from_closeTime = $this->barBuffer[0]['time'] + 1*MINUTE;
      }
      $this->full_bars         = $this->stored_bars + $bufferSize;
      $this->full_to_offset    = $this->full_bars - 1;
      $this->full_to_openTime  = $this->barBuffer[$bufferSize-1]['time'];
      $this->full_to_closeTime = $this->barBuffer[$bufferSize-1]['time'] + 1*MINUTE;

      $this->lastM1DataTime    = $bars[sizeOf($bars)-1]['time'];
      $this->full_lastSyncTime = $this->lastM1DataTime + 1*MINUTE;

      if ($bufferSize > $this->barBufferSize)
         $this->flush($this->barBufferSize);
   }


   /**
    * F�gt der M5-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToM5(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize = sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         if ($bar['time'] < $this->full_to_closeTime) {                       // Wechsel zur n�chsten M5-Bar erkennen
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
            // Metadaten aktualisieren
            $this->lastM1DataTime    = $bar['time'];
            $this->full_lastSyncTime = $this->lastM1DataTime + 1*MINUTE;
         }
         else {
            // neue Bar beginnen
            $openTime           =  $bar['time'] - $bar['time']%5*MINUTES;
            $this->barBuffer[]  =  $bar;
            $currentBar         =& $this->barBuffer[$bufferSize++];
            $currentBar['time'] =  $openTime;
            $closeTime          =  $openTime + 5*MINUTES;

            // Metadaten aktualisieren
            if (!$this->full_bars) {                                          // History ist noch leer
               $this->full_from_offset    = 0;
               $this->full_from_openTime  = $openTime;
               $this->full_from_closeTime = $closeTime;
            }
            $this->full_bars         = $this->stored_bars + $bufferSize;
            $this->full_to_offset    = $this->full_bars - 1;
            $this->full_to_openTime  = $openTime;
            $this->full_to_closeTime = $closeTime;

            $this->lastM1DataTime    = $bar['time'];
            $this->full_lastSyncTime = $this->lastM1DataTime + 1*MINUTE;

            // ggf. Buffer flushen
            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
      }
   }


   /**
    * F�gt der M15-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToM15(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize = sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten M15-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $bar['time']            -=  $bar['time'] % 15*MINUTES;
            $this->full_to_closeTime =  $bar['time'] + 15*MINUTES;
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * F�gt der M30-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToM30(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize = sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten M30-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $bar['time']            -=  $bar['time'] % 30*MINUTES;
            $this->full_to_closeTime =  $bar['time'] + 30*MINUTES;
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * F�gt der H1-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToH1(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize = sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten H1-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $bar['time']            -=  $bar['time'] % HOUR;
            $this->full_to_closeTime =  $bar['time'] + 1*HOUR;
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * F�gt der H4-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToH4(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize = sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten H4-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $bar['time']            -=  $bar['time'] % 4*HOURS;
            $this->full_to_closeTime =  $bar['time'] + 4*HOURS;
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * F�gt der D1-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToD1(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize = sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten D1-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $bar['time']            -=  $bar['time'] % DAY;
            $this->full_to_closeTime =  $bar['time'] + 1*DAY;
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * F�gt der W1-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToW1(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize =  sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten W1-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $dow = (int) gmDate('w', $bar['time']);
            $bar['time']            -=  $bar['time']%DAY + (($dow+6)%7)*DAYS;  // 00:00, Montag (Operator-Precedence beachten)
            $this->full_to_closeTime =  $bar['time'] + 1*WEEK;
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * F�gt der MN1-History dieser Instanz weitere Daten hinzu.
    *
    * @param  MYFX_BAR[] $bars - Bardaten der Periode M1
    */
   private function appendToMN1(array $bars) {
      if ($this->closed)                             throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!$bars) return;
      if ($bars[0]['time'] <= $this->lastM1DataTime) throw new IllegalStateException('Cannot append bar(s) of '.gmDate('D, d-M-Y H:i:s', $bars[0]['time']).' to history ending at '.gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime));

      $currentBar = null;
      $bufferSize =  sizeOf($this->barBuffer);
      if ($bufferSize)
         $currentBar =& $this->barBuffer[$bufferSize-1];

      foreach ($bars as $bar) {
         // Wechsel zur n�chsten MN1-Bar erkennen
         if ($bar['time'] >= $this->full_to_closeTime) {
            // neue Bar beginnen
            $dom = (int) gmDate('d', $bar['time']);
            $m   = (int) gmDate('m', $bar['time']);
            $y   = (int) gmDate('Y', $bar['time']);
            $bar['time']            -=  $bar['time']%DAYS + ($dom-1)*DAYS;    // 00:00, 1. des Monats (Operator-Precedence beachten)
            $this->full_to_closeTime =  gmMkTime(0, 0, 0, $m+1, 1, $y);       // 00:00, 1. des n�chsten Monats
            $this->barBuffer[]       =  $bar;
            $currentBar              =& $this->barBuffer[$bufferSize++];

            if ($bufferSize > $this->barBufferSize)
               $bufferSize -= $this->flush($this->barBufferSize);
         }
         else {
            // letzte Bar aktualisieren ('time' und 'open' unver�ndert)
            if ($bar['high'] > $currentBar['high']) $currentBar['high' ]  = $bar['high' ];
            if ($bar['low' ] < $currentBar['low' ]) $currentBar['low'  ]  = $bar['low'  ];
                                                    $currentBar['close']  = $bar['close'];
                                                    $currentBar['ticks'] += $bar['ticks'];
         }
      }
   }


   /**
    * Schreibt eine Anzahl Bars aus dem Barbuffer in die History-Datei.
    *
    * @param  int $count - Anzahl zu schreibender Bars (default: alle Bars)
    *
    * @return int - Anzahl der geschriebenen und aus dem Buffer gel�schten Bars
    */
   public function flush($count=PHP_INT_MAX) {
      if ($this->closed)   throw new IllegalStateException('Cannot process a closed '.__CLASS__);
      if (!is_int($count)) throw new IllegalTypeException('Illegal type of parameter $count: '.getType($count));
      if ($count < 0)      throw new plInvalidArgumentException('Invalid parameter $count: '.$count);

      $bufferSize = sizeOf($this->barBuffer);
      $todo       = min($bufferSize, $count);
      if (!$todo) return 0;

      $divider = pow(10, $this->getDigits());
      $i = 0;


      // (1) FilePointer setzen
      fSeek($this->hFile, HistoryHeader::SIZE + ($this->stored_to_offset+1)*$this->barSize);


      // (2) Bars schreiben
      foreach ($this->barBuffer as $i => $bar) {
         $T = $bar['time' ];
         $O = $bar['open' ]/$divider;
         $H = $bar['high' ]/$divider;
         $L = $bar['low'  ]/$divider;
         $C = $bar['close']/$divider;
         $V = $bar['ticks'];

         MT4::appendHistoryBar400($this->hFile, $this->getDigits(), $T, $O, $H, $L, $C, $V);
         if ($i+1 == $todo)
            break;
      }
      //if ($this->getPeriod()==PERIOD_M1) echoPre(__METHOD__.'()  wrote '.$todo.' bars, lastBar.time='.gmDate('D, d-M-Y H:i:s', $this->barBuffer[$todo-1]['time']));


      // (3) Metadaten aktualisieren
      if (!$this->stored_bars) {                                           // Datei war vorher leer
         $this->stored_from_offset    = 0;
         $this->stored_from_openTime  = $this->barBuffer[0]['time'];
         $this->stored_from_closeTime = MyFX::periodCloseTime($this->stored_from_openTime, $this->getPeriod());
      }
      $this->stored_bars         = $this->stored_bars + $todo;
      $this->stored_to_offset    = $this->stored_bars - 1;
      $this->stored_to_openTime  = $this->barBuffer[$todo-1]['time'];
      $this->stored_to_closeTime = MyFX::periodCloseTime($this->stored_to_openTime, $this->getPeriod());

      // lastSyncTime je nachdem setzen, ob noch weitere Daten im Buffer sind
      $this->stored_lastSyncTime = ($todo < $bufferSize) ? $this->stored_to_closeTime : $this->lastM1DataTime + 1*MINUTE;

      //$this->full* �ndert sich nicht


      // (4) HistoryHeader aktualisieren
      $this->hstHeader->setLastSyncTime($this->stored_lastSyncTime);
      $this->writeHistoryHeader();


      // (5) Barbuffer um die geschriebenen Bars k�rzen
      if ($todo == $bufferSize) $this->barBuffer = array();
      else                      $this->barBuffer = array_slice($this->barBuffer, $todo);

      return $todo;
   }


   /**
    * Schreibt den HistoryHeader in die Datei.
    *
    * @return int - Anzahl der geschriebenen Bytes
    */
   private function writeHistoryHeader() {
      fSeek($this->hFile, 0);
      $format  = HistoryHeader::packFormat();
      $written = fWrite($this->hFile, pack($format, $this->hstHeader->getFormat(),           // V
                                                    $this->hstHeader->getCopyright(),        // a64
                                                    $this->hstHeader->getSymbol(),           // a12
                                                    $this->hstHeader->getPeriod(),           // V
                                                    $this->hstHeader->getDigits(),           // V
                                                    $this->hstHeader->getSyncMarker(),       // V
                                                    $this->hstHeader->getLastSyncTime()));   // V
                                                                                             // x52
      //if ($this->getPeriod()==PERIOD_M1 && $this->hstHeader->getLastSyncTime()) $this->showMetaData();
      return $written;
   }


   /**
    * Nur zum Debuggen
    */
   public function showMetaData($showStored=true, $showFull=true, $showFile=true) {
      $Pxx = MyFX::periodDescription($this->getPeriod());

      if ($showStored) {
         echoPre($Pxx.'::stored_bars           = '. $this->stored_bars);
         echoPre($Pxx.'::stored_from_offset    = '. $this->stored_from_offset);
         echoPre($Pxx.'::stored_from_openTime  = '.($this->stored_from_openTime  ? gmDate('D, d-M-Y H:i:s', $this->stored_from_openTime ) : 0));
         echoPre($Pxx.'::stored_from_closeTime = '.($this->stored_from_closeTime ? gmDate('D, d-M-Y H:i:s', $this->stored_from_closeTime) : 0));
         echoPre($Pxx.'::stored_to_offset      = '. $this->stored_to_offset);
         echoPre($Pxx.'::stored_to_openTime    = '.($this->stored_to_openTime    ? gmDate('D, d-M-Y H:i:s', $this->stored_to_openTime   ) : 0));
         echoPre($Pxx.'::stored_to_closeTime   = '.($this->stored_to_closeTime   ? gmDate('D, d-M-Y H:i:s', $this->stored_to_closeTime  ) : 0));
         echoPre($Pxx.'::stored_lastSyncTime   = '.($this->stored_lastSyncTime   ? gmDate('D, d-M-Y H:i:s', $this->stored_lastSyncTime  ) : 0));
      }
      $showStored && $showFull && echoPre(NL);

      if ($showFull) {
         echoPre($Pxx.'::full_bars             = '. $this->full_bars);
         echoPre($Pxx.'::full_from_offset      = '. $this->full_from_offset);
         echoPre($Pxx.'::full_from_openTime    = '.($this->full_from_openTime    ? gmDate('D, d-M-Y H:i:s', $this->full_from_openTime   ) : 0));
         echoPre($Pxx.'::full_from_closeTime   = '.($this->full_from_closeTime   ? gmDate('D, d-M-Y H:i:s', $this->full_from_closeTime  ) : 0));
         echoPre($Pxx.'::full_to_offset        = '. $this->full_to_offset);
         echoPre($Pxx.'::full_to_openTime      = '.($this->full_to_openTime      ? gmDate('D, d-M-Y H:i:s', $this->full_to_openTime     ) : 0));
         echoPre($Pxx.'::full_to_closeTime     = '.($this->full_to_closeTime     ? gmDate('D, d-M-Y H:i:s', $this->full_to_closeTime    ) : 0));
         echoPre($Pxx.'::full_lastSyncTime     = '.($this->full_lastSyncTime     ? gmDate('D, d-M-Y H:i:s', $this->full_lastSyncTime    ) : 0));
      }
      ($showStored || $showFull) && echoPre(NL);

      if ($showFile) {
         echoPre($Pxx.'::lastM1DataTime        = '.($this->lastM1DataTime        ? gmDate('D, d-M-Y H:i:s', $this->lastM1DataTime       ) : 0));
         echoPre($Pxx.'::fp                    = '.($fp=fTell($this->hFile)).' (bar offset '.(($fp-HistoryHeader::SIZE)/$this->barSize).')');
      }
      echoPre(NL);
   }
}
