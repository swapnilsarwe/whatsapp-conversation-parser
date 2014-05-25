<?php
class SQLiteDB extends SQLite3
{
    function __construct($dbFileName)
    {
        $this->open($dbFileName);
    }
}

function getAlphaNumericUrl($url)
{
    $pattern = '/[^A-Za-z0-9]/';
    $replacement = '';
    return strtolower(preg_replace($pattern, $replacement, $url));
}

function returnIfPresent($array, $index, $none = null) //$none => Value to be returned on failure
{
    return isset($array[$index]) ? trim($array[$index]) : $none;
}

function isValueSet($string)
{
    return (!is_null($string) && trim($string) !== '');
}

function date_time_format($datetime)
{
    $arrDate = explode(",", $datetime);
    switch (count($arrDate)) {
        case 2:
            // for conversations of current year there is no year mentioned
            $date = DateTime::createFromFormat('M j, g:i A', $datetime);
            return $date->format('Y-m-d H:i:s');

        case 3:
            // for conversations of past year there is year mentioned
            $date = DateTime::createFromFormat('M j, Y, g:i A', $datetime);
            return $date->format('Y-m-d H:i:s');
    }
    return FALSE;
}

class WhatsappParser
{
    private static $instance;
    private $sqliteDbInstance;
    private $dbTable = "whatsapp_conversation";
    private $fileToParse;
    private $dbName;
    private $debug = true;
    private $lastInsertId = 0;
    // regec to match the single complete message
    private $msgRegex = '(([A-Za-z]{1,3}\s[0-9]{1,2},\s[0-9]{1,2}[:]{1}[0-9]{1,2}\s[APM]{2})|([A-Za-z]{1,3}\s[0-9]{1,2},\s[0-9]{1,4},\s[0-9]{1,2}[:]{1}[0-9]{1,2}\s[APM]{2}))\s-\s(.*):\s([\s\S]*)';

    public function __construct($fileToParse)
    {
        $this->fileToParse = $fileToParse;
        $this->dbName = getAlphaNumericUrl($this->fileToParse);
        $this->dbTable .= "_" . $this->dbName;
    }

    public static function getInstance($fileToParse)
    {
        if (is_null(self::$instance)) {
            self::$instance = new self($fileToParse);
        }
        return self::$instance;
    }

    private function getSQLiteDBInstance()
    {
        $dbFileName = $this->dbTable . ".db";
        if (is_null($this->sqliteDbInstance)) {
            $this->sqliteDbInstance = new SQLiteDB($dbFileName);
            if (!$this->sqliteDbInstance) {
                echo $this->sqliteDbInstance->lastErrorMsg();
            }
        }
        return $this->sqliteDbInstance;
    }

    private function logToFile($msg)
    {
        $strMessage = date("Y-m-d H:i:s") . " " . $msg . "\n";
        if ($this->debug) {
            echo $strMessage;
        }
        file_put_contents("whatspp-" . date("Y-m-d-H") . ".log", $strMessage, FILE_APPEND);
    }

    private function createTable()
    {
        $this->getSQLiteDBInstance()->exec("    CREATE TABLE IF NOT EXISTS  " . $this->dbTable . "
                                                        (   'id' INTEGER PRIMARY KEY,
                                                            'datetime' datetime,
                                                            'author' varchar(32),
                                                            'message' TEXT,
                                                            unique ('datetime', 'author', 'message') ON CONFLICT REPLACE
                                                        ) ");
    }

    private function checkIfExists($row)
    {
        $sql = "SELECT *
                     FROM       " . $this->dbTable . "
                     WHERE      datetime='" . $this->getSQLiteDBInstance()->escapeString($row["datetime"]) . "'
                            AND author='" . $this->getSQLiteDBInstance()->escapeString($row["author"]) . "'
                            AND message='" . $this->getSQLiteDBInstance()->escapeString($row["message"]) . "'";
        $results = $this->getSQLiteDBInstance()->query($sql);

        while ($row = $results->fetchArray()) {
            return TRUE;
        }
        return FALSE;
    }

    private function storeData($row = array())
    {
        if ($this->checkIfExists($row)) {
            return TRUE;
        }
        $fields = array_keys($row);
        $values = array_values($row);

        foreach ($values as $key => $val) {
            $values[$key] = $this->getSQLiteDBInstance()->escapeString($val);
        }
        $strFields = "'" . implode("','", $fields) . "'";
        $strValues = "'" . implode("','", $values) . "'";
        $sql = "INSERT INTO " . $this->dbTable . " (" . $strFields . ") VALUES (" . $strValues . ")";
        if (!$this->getSQLiteDBInstance($sql)->query($sql)) {
            echo "Error:" . $sql;
            return FALSE;
        }
        $this->lastInsertId = $this->getSQLiteDBInstance()->lastInsertRowID();
        return TRUE;
    }

    private function parseAndStore($msg)
    {
        $arrRecord = array();
        $matches = array();
        $regEx = '/' . $this->msgRegex . '/i';
        preg_match($regEx, $msg, $matches);
        if (count($matches) == 6) {
            $dateField = (isValueSet(returnIfPresent($matches, "1"))) ? returnIfPresent($matches, "2") : returnIfPresent($matches, "3");
            $arrRecord["datetime"] = date_time_format($dateField);
            $arrRecord["author"] = returnIfPresent($matches, "4");
            $arrRecord["message"] = returnIfPresent($matches, "5");
        }

        if (count($arrRecord) > 0) {
            return $this->storeData($arrRecord);
        }
        return false;
    }

    private function store($buffer)
    {
        if (!$this->parseAndStore($buffer)) {
            $this->logToFile($buffer);
        }
    }

    private function readFileAndStoreData()
    {
        $handle = @fopen($this->fileToParse, "r");
        if ($handle) {
            $tmpBuffer = "";
            $line = 0;
            while (($buffer = fgets($handle)) !== false) {
                $matches = array();
                $regEx = '/' . $this->msgRegex . '/';
                preg_match($regEx, $buffer, $matches);
                if (count($matches) == 6 && $line != 0) {
                    $this->store($tmpBuffer);
                    $tmpBuffer = $buffer;
                } else {
                    $tmpBuffer .= $buffer;
                }
                $line++;
            }
            $this->store($tmpBuffer);
        }
        exit("DONE...");
    }

    public function parse()
    {
        $this->createTable();
        $this->readFileAndStoreData();
    }

}

$fileToParse = (isset($_SERVER["argv"]) && $_SERVER["argv"][1]) ? $_SERVER["argv"][1] : '';
if (trim($fileToParse) == "") {
    echo "\nNo File Provided\n";
} else {
    WhatsappParser::getInstance($fileToParse)->parse();
}