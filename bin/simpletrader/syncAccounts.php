#!/usr/bin/env php
<?php
/**
 * Synchronisiert die Daten ein oder mehrerer Signale mit den lokal gespeicherten Daten (Datenbank und MT4-Datenfiles).
 * Bei Datenaenderung kann eine Mail oder eine SMS verschickt werden.
 */
namespace rosasurfer\xtrade\simpletrader\sync_accounts;

use rosasurfer\exception\IllegalTypeException;
use rosasurfer\exception\InfrastructureException;
use rosasurfer\exception\RuntimeException;
use rosasurfer\log\Logger;
use rosasurfer\xtrade\ReportHelper;
use rosasurfer\xtrade\XTrade;
use rosasurfer\xtrade\metatrader\MT4;
use rosasurfer\xtrade\model\ClosedPosition;
use rosasurfer\xtrade\model\ClosedPositionDAO;
use rosasurfer\xtrade\model\OpenPosition;
use rosasurfer\xtrade\model\OpenPositionDAO;
use rosasurfer\xtrade\model\Signal;
use rosasurfer\xtrade\model\SignalDAO;
use rosasurfer\xtrade\model\metatrader\Account;
use rosasurfer\xtrade\simpletrader\SimpleTrader;
use rosasurfer\xtrade\simpletrader\SimpleTraderException;

require(dirName(realPath(__FILE__)).'/../../app/init.php');


$sleepSeconds      = 30;         // Laenge der Pause zwischen zwei Updates
$signalNamePadding = 21;         // Padding der Anzeige des Signalnamens:  @see function processSignal()


// --- Start ----------------------------------------------------------------------------------------------------------------


// (1) Befehlszeilenargumente einlesen und validieren
$args = array_slice($_SERVER['argv'], 1);


// (1.1) Optionen parsen
$looping = $fileSyncOnly = false;
foreach ($args as $i => $arg) {
    $arg = strToLower($arg);
    if (in_array($arg, ['-h','--help'])) { exit(1|help());                                 }     // Hilfe
    if (in_array($arg, ['-l']))          { $looping     =true; unset($args[$i]); continue; }     // -l=Looping
    if (in_array($arg, ['-f']))          { $fileSyncOnly=true; unset($args[$i]); continue; }     // -f=FileSyncOnly
}


// (1.2) Gross-/Kleinschreibung normalisieren
foreach ($args as $i => $arg) {
    $args[$i] = strToLower($arg);
}
$args = $args ? array_unique($args) : ['*'];                   // ohne Signal-Parameter werden alle Signale synchronisiert


// (2) Erreichbarkeit der Datenbank pruefen                    // Als Extra-Schritt, damit ein Connection-Fehler bei Programmstart nur eine
try {                                                          // kurze Fehlermeldung, waehrend der Programmausfuehrung jedoch einen kritischen
    Signal::db()->connect();                                   // Fehler (mit Stacktrace) ausloest.
}
catch (InfrastructureException $ex) {
    strStartsWithI($ex->getMessage(), 'can not connect') && exit(1|echoPre($ex->getMessage()));
    throw $ex;
}


// (3) Signale aktualisieren
while (true) {
    foreach ($args as $i => $arg) {
        !processSignal($arg, $fileSyncOnly) && exit(1);
    }
    if (!$looping) break;
    sleep($sleepSeconds);                                       // vorm naechsten Durchlauf jeweils einige Sek. schlafen
}


// (4) Ende
exit(0);


// --- Funktionen -----------------------------------------------------------------------------------------------------------


/**
 * Aktualisiert die Daten eines Signals.
 *
 * @param  string $alias        - Signalalias
 * @param  bool   $fileSyncOnly - ob alle Daten oder nur die MT4-Dateien aktualisiert werden sollen
 *
 * @return bool - Erfolgsstatus
 */
function processSignal($alias, $fileSyncOnly) {
    if (!is_string($alias))      throw new IllegalTypeException('Illegal type of parameter $alias: '.getType($alias));
    if (!is_bool($fileSyncOnly)) throw new IllegalTypeException('Illegal type of parameter $fileSyncOnly: '.getType($fileSyncOnly));

    /** @var SignalDAO $signalDao */
    $signalDao = Signal::dao();

    // if the wildcard "*" is specified recursively process all active accounts
    if ($alias == '*') {
        $me = __FUNCTION__;
        foreach ($signalDao->listActiveSimpleTrader() as $signal) {
            $me($signal->getAlias(), $fileSyncOnly);
        }
        return true;
    }

    $signal = $signalDao->getByProviderAndAlias($provider='simpletrader', $alias);
    if (!$signal) return false(echoPre('Invalid or unknown signal: "'.$provider.':'.$alias.'"'));

    global $signalNamePadding;                               // output formatting: whether or not the last function call
    static $openUpdates=false, $closedUpdates=false;         //                    detected open trade/history changes
    echo(($openUpdates ? NL:'').str_pad($signal->getName().' ', $signalNamePadding, '.', STR_PAD_RIGHT).' ');


    if (!$fileSyncOnly) {
        $counter     = 0;
        $fullHistory = false;

        while (true) {
            $counter++;

            // HTML-Seite laden
            $html = SimpleTrader::loadSignalPage($signal, $fullHistory);

            // HTML-Seite parsen
            $openPositions = $closedPositions = [];
            $errorMsg = SimpleTrader::parseSignalData($signal, $html, $openPositions, $closedPositions);

            // bei PHP-Fehlermessages in HTML-Seite URL nochmal laden (bis zu 5 Versuche)
            if ($errorMsg) {
                echoPre($errorMsg);
                if ($counter >= 5) throw new RuntimeException($signal->getName().': '.$errorMsg);
                Logger::log($signal->getName().': '.$errorMsg."\nretrying...", L_WARN);
                continue;
            }

            // Datenbank aktualisieren
            try {
                if (!updateDatabase($signal, $openPositions, $openUpdates, $closedPositions, $closedUpdates, $fullHistory))
                    return false;
                break;
            }
            catch (SimpleTraderException $ex) {
                if ($fullHistory) throw $ex;              // Fehler weiterreichen, wenn er mit kompletter History auftrat

                // Zaehler zuruecksetzen und komplette History laden
                $counter     = 0;
                $fullHistory = true;
                echoPre($ex->getMessage().', loading full history...');
            }
        }
    }
    else {
      $openUpdates = $closedUpdates = false;
    }

    // update MQL account history files
    MT4::updateAccountHistory($signal, $openUpdates, $closedUpdates);

    return true;
}


/**
 * Aktualisiert die lokalen offenen und geschlossenen Positionen. Partielle Closes lassen sich nicht vollstaendig erkennen
 * und werden daher wie regulaere Positionen behandelt und gespeichert.
 *
 * @param  Signal $signal               - Signal
 * @param  array  $currentOpenPositions - Array mit aktuell offenen Positionen
 * @param  bool  &$openUpdates          - Variable, die nach Rueckkehr anzeigt, ob Aenderungen an den offenen Positionen detektiert wurden oder nicht
 * @param  array  $currentHistory       - Array mit aktuellen Historydaten
 * @param  bool  &$closedUpdates        - Variable, die nach Rueckkehr anzeigt, ob Aenderungen an der Trade-History detektiert wurden oder nicht
 * @param  bool   $fullHistory          - ob die komplette History geladen wurde (fuer korrektes Padding der Anzeige)
 *
 * @return bool - Erfolgsstatus
 *
 * @throws SimpleTraderException - wenn die aelteste geschlossene Position lokal nicht vorhanden ist (auch beim ersten Synchronisieren)
 *                               - wenn eine beim letzten Synchronisieren offene Position weder als offen noch als geschlossen angezeigt wird
 */
function updateDatabase(Signal $signal, array &$currentOpenPositions, &$openUpdates, array &$currentHistory, &$closedUpdates, $fullHistory) {   // die zusaetzlichen Zeiger minimieren den Speicherverbrauch
    if (!is_bool($openUpdates))   throw new IllegalTypeException('Illegal type of parameter $openUpdates: '.getType($openUpdates));
    if (!is_bool($closedUpdates)) throw new IllegalTypeException('Illegal type of parameter $closedUpdates: '.getType($closedUpdates));
    if (!is_bool($fullHistory))   throw new IllegalTypeException('Illegal type of parameter $fullHistory: '.getType($fullHistory));

    $unchangedOpenPositions   = 0;
    $positionChangeStartTimes = [];                                   // Beginn der Aenderungen der Net-Position
    $lastKnownChangeTimes     = [];
    $modifications            = [];

    /** @var OpenPositionDAO $openPositionDao */
    $openPositionDao = OpenPosition::dao();
    /** @var ClosedPositionDAO $closedPositionDao */
    $closedPositionDao = ClosedPosition::dao();
    /** @var SignalDAO $signalDao */
    $signalDao = Signal::dao();

    $db = Signal::db();
    $db->begin();
    try {
        // (1) bei partieller History pruefen, ob die aelteste geschlossene Position lokal vorhanden ist
        if (!$fullHistory) {
            foreach ($currentHistory as $data) {
                if (!$data) continue;                                    // Datensaetze uebersprungener Zeilen koennen leer sein.
                $ticket = $data['ticket'];
                if (!$closedPositionDao->isTicket($signal, $ticket))
                    throw new SimpleTraderException('Closed position #'.$ticket.' not found locally');
                break;
            }
        }


        // (2) lokalen Stand der offenen Positionen holen
        $knownOpenPositions = $openPositionDao->listBySignal($signal, $assocTicket=true);


        // (3) offene Positionen abgleichen
        foreach ($currentOpenPositions as $i => $data) {
            if (!$data) continue;                                       // Datensaetze uebersprungener Zeilen koennen leer sein.
            $sTicket = (string)$data['ticket'];

            if (!isSet($knownOpenPositions[$sTicket])) {
                // (3.1) neue offene Position
                if (!isSet($positionChangeStartTimes[$data['symbol']]))
                    $lastKnownChangeTimes[$data['symbol']] = $signalDao->getLastKnownPositionChangeTime($signal, $data['symbol']);

                $position = OpenPosition::create($signal, $data)->save();
                $symbol   = $position->getSymbol();
                $openTime = $position->getOpenTime();
                $positionChangeStartTimes[$symbol] = isSet($positionChangeStartTimes[$symbol]) ? min($openTime, $positionChangeStartTimes[$symbol]) : $openTime;
            }
            else {
                // (3.2) bekannte offene Position auf geaenderte Limite pruefen
                $position = null;
                if ($data['takeprofit'] != ($prevTP=$knownOpenPositions[$sTicket]->getTakeProfit())) $position = $knownOpenPositions[$sTicket]->setTakeProfit($data['takeprofit']);
                if ($data['stoploss'  ] != ($prevSL=$knownOpenPositions[$sTicket]->getStopLoss())  ) $position = $knownOpenPositions[$sTicket]->setStopLoss  ($data['stoploss'  ]);
                if ($position) {
                    $modifications[$position->save()->getSymbol()][] = [
                        'position' => $position,
                        'prevTP'   => $prevTP,
                        'prevSL'   => $prevSL
                    ];
                }
                else $unchangedOpenPositions++;
                unset($knownOpenPositions[$sTicket]);                    // bekannte offene Position aus Liste loeschen
            }
        }


        // (4) History abgleichen ($currentHistory ist sortiert nach CloseTime+OpenTime+Ticket)
        $formerOpenPositions = $knownOpenPositions;                    // Alle in $knownOpenPositions uebrig gebliebenen Positionen existierten nicht
        $hstSize             = sizeOf($currentHistory);                // in $currentOpenPositions und muessen daher geschlossen worden sein.
        $matchingPositions   = $otherClosedPositions = 0;
        $openGotClosed       = false;

        for ($i=$hstSize-1; $i >= 0; $i--) {                           // Die aufsteigende History wird rueckwaerts verarbeitet (schnellste Variante).
            if (!$data=$currentHistory[$i])                             // Datensaetze uebersprungener Zeilen koennen leer sein.
                continue;
            $ticket       = $data['ticket'];
            $openPosition = null;

            if ($formerOpenPositions) {
                $sTicket = (string) $ticket;
                if (isSet($formerOpenPositions[$sTicket])) {
                    $openPosition = $openPositionDao->getByTicket($signal, $ticket);
                    unset($formerOpenPositions[$sTicket]);
                }
            }

            if (!$openPosition && $closedPositionDao->isTicket($signal, $ticket)) {
                $matchingPositions++;
                if ($matchingPositions >= 3 && !$formerOpenPositions)    // Nach Uebereinstimmung von 3 Datensaetzen wird abgebrochen.
                    break;
                continue;
            }

            if (!isSet($positionChangeStartTimes[$data['symbol']]))
                $lastKnownChangeTimes[$data['symbol']] = $signalDao->getLastKnownPositionChangeTime($signal, $data['symbol']);

            // Position in t_closedposition einfuegen
            if ($openPosition) {
                $closedPosition = ClosedPosition::create($openPosition, $data)->save();
                $symbol         = $closedPosition->getSymbol();
                $closeTime      = $closedPosition->getCloseTime();
                $positionChangeStartTimes[$symbol] = isSet($positionChangeStartTimes[$symbol]) ? min($closeTime, $positionChangeStartTimes[$symbol]) : $closeTime;
                $openPosition->delete();                                 // vormals offene Position aus t_openposition loeschen
                $openGotClosed = true;
            }
            else {
                $closedPosition = ClosedPosition::create($signal, $data)->save();
                $symbol         = $closedPosition->getSymbol();
                $closeTime      = $closedPosition->getCloseTime();
                $positionChangeStartTimes[$symbol] = isSet($positionChangeStartTimes[$symbol]) ? min($closeTime, $positionChangeStartTimes[$symbol]) : $closeTime;
                $otherClosedPositions++;
            }
        }


        // (5) ohne Aenderungen
        if (!$positionChangeStartTimes && !$modifications) {
            echoPre('no changes'.($unchangedOpenPositions ? ' ('.$unchangedOpenPositions.' open position'.pluralize($unchangedOpenPositions).')':''));
        }


        // (6) bei Aenderungen: formatierter und sortierter Report
        if ($positionChangeStartTimes) {
            global $signalNamePadding;
            $symbol = null;
            $n = 0;

            // (6.1) Positionsaenderungen
            foreach ($positionChangeStartTimes as $symbol => $startTime) {
                $n++;
                if ($startTime < $lastKnownChangeTimes[$symbol])
                    $startTime = XTrade::fxtDate(XTrade::fxtStrToTime($lastKnownChangeTimes[$symbol]) + 1);

                $report = ReportHelper::getNetPositionHistory($signal, $symbol, $startTime);
                $oldNetPosition     = 'Flat';
                $oldNetPositionDone = false;
                $netPosition        = '';
                $iFirstNewRow       = 0;

                foreach ($report as $i => $row) {
                    if      ($row['total' ] > 0) $netPosition  = 'Long  '.numf( $row['total'], 2);
                    else if ($row['total' ] < 0) $netPosition  = 'Short '.numf(-$row['total'], 2);
                    else if ($row['hedged'])     $netPosition  = 'Hedge '.str_repeat(' ', strLen(numf(abs($report[$i-1]['total']), 2)));

                    if      ($row['hedged'])     $netPosition .= ' +-'.numf($row['hedged'], 2).' lot';
                    else if ($row['total' ])     $netPosition .= ' lot';
                    else                         $netPosition  = 'Flat';

                    if ($row['time'] >= $startTime) {
                        if (!$oldNetPositionDone) {
                            $iFirstNewRow       = $i;                                           // keine Anzeige von $oldNetPosition bei nur einem
                            if (sizeOf($report) == $iFirstNewRow+1) echoPre("\n");              // neuen Trade
                            else                                    echoPre(($n==1 && !$fullHistory ? '' : str_pad("\n", $signalNamePadding+2, ' ', STR_PAD_RIGHT)).str_repeat(' ', $signalNamePadding+14).'was: '.$oldNetPosition);
                            $oldNetPositionDone = true;
                        }
                        $format = "%s:  %-6s %-4s %4.2F %s @ %-8s now: %s";
                        $date   = date('Y-m-d H:i:s', XTrade::fxtStrToTime($row['time'  ]));
                        $deal   =            ($row['trade']=='open') ? '': $row['trade' ];      // "open" wird nicht extra angezeigt
                        $type   =                                  ucFirst($row['type'  ]);
                        $lots   =                                          $row['lots'  ];
                        $symbol =                                          $row['symbol'];      // Consolen-Output fuer "[open|close] position...",
                        $price  =                                          $row['price' ];      // "modify ..." in SimpleTrader::onPositionModify()
                        echoPre(sprintf($format, $date, $deal, $type, $lots, $symbol, $price, $netPosition));
                    }
                    else $oldNetPosition = $netPosition;
                }
                SimpleTrader::onPositionChange($signal, $symbol, $report, $iFirstNewRow, $oldNetPosition, $netPosition);
            }

            // (6.2) Limitaenderungen des jeweiligen Symbols nach Positionsaenderung anfuegen
            if (isSet($modifications[$symbol])) {
                foreach ($modifications[$symbol] as $modification)
                    SimpleTrader::onPositionModify($modification['position'], $modification['prevTP'], $modification['prevSL']);
                unset($modifications[$symbol]);
            }
        }

        // (6.3) restliche Limitaenderungen fuer Symbole ohne Postionsaenderung
        if ($modifications) {
            !$positionChangeStartTimes && echoPre(NL);
            foreach ($modifications as $modsPerSymbol) {
                foreach ($modsPerSymbol as $modification) {
                    SimpleTrader::onPositionModify($modification['position'], $modification['prevTP'], $modification['prevSL']);
                }
            }
        }


        // (7) nicht zuzuordnende Positionen: ggf. muss die komplette History geladen werden
        if ($formerOpenPositions) {
            $errorMsg = null;
            if (!$fullHistory) {
                $errorMsg = 'Close data not found for former open position #'.array_shift($formerOpenPositions)->getTicket();
            }
            else {
                $errorMsg = 'Found '.sizeOf($formerOpenPositions).' former open position'.pluralize(sizeOf($formerOpenPositions))
                             ." now neither in \"openTrades\" nor in \"history\":\n".printPretty($formerOpenPositions, true);
            }
            throw new SimpleTraderException($errorMsg);
        }


        // (8) alles speichern
        $db->commit();
    }
    catch (\Exception $ex) {
        $db->rollback();
        throw $ex;
    }

    $openUpdates   = $positionChangeStartTimes || $modifications;
    $closedUpdates = $openGotClosed || $otherClosedPositions;

    return true;
}


/**
 * Hilfefunktion: Zeigt die Syntax des Aufrufs an.
 *
 * @param  string $message [optional] - zusaetzlich zur Syntax anzuzeigende Message (default: keine)
 */
function help($message = null) {
    if (!is_null($message))
        echo($message."\n");

    $self = baseName($_SERVER['PHP_SELF']);

echo <<<HELP

 Syntax:  $self [-l] [-f] [signal_name ...]

 Options:  -l  Runs infinitely and synchronizes every 30 seconds.
           -f  Synchronizes MetaTrader data files but not the database (does not go online).
           -h  This help screen.


HELP;
}
