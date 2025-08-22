<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/_traits.php';  // Generell funktions

// CLASS StockWidget
class StockWidget extends IPSModuleStrict
{
    use DebugHelper;
    use FormatHelper;

    /**
     * @var int Min IPS Object ID
     */
    private const IPS_MIN_ID = 10000;

    /**
     * @var string Archive GUID
     */
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    /**
     * @var array<int,string>
     */
    private const TWSW_MAP_PERIOD = [
        1   => '1 D',
        7   => '1 W',
        30  => '1 M',
        90  => '1 Q',
        180 => '1 H',
        356 => '1 Y'
    ];

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Stock ...
        $this->RegisterPropertyString('StockLabel', '');
        $this->RegisterPropertyInteger('StockFont', 10);
        // Trend ...
        $this->RegisterPropertyInteger('TrendVariable', 1);
        $this->RegisterPropertyInteger('TrendFont', 12);
        $this->RegisterPropertyInteger('TrendPositive', 0x00FF00);
        $this->RegisterPropertyInteger('TrendNegative', 0xFF0000);
        // Chart ...
        $this->RegisterPropertyInteger('ChartData', 1);
        $this->RegisterPropertyInteger('ChartLine', 0x11A0F3);
        $this->RegisterPropertyBoolean('ChartSmooth', true);
        $this->RegisterPropertyBoolean('ChartFill', true);
        $this->RegisterPropertyInteger('ChartOffset', 0);
        // Price ...
        $this->RegisterPropertyInteger('PriceVariable', 1);
        $this->RegisterPropertyInteger('PriceFont', 18);

        // Set visualization type to 1, as we want to offer HTML
        $this->SetVisualizationType(1);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Delete all references in order to readd them
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        // Delete all registrations in order to readd them
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register all references
        $variables = ['TrendVariable', 'PriceVariable'];
        foreach ($variables as $variable) {
            $vid = $this->ReadPropertyInteger($variable);
            if ($vid >= self::IPS_MIN_ID) {
                if (IPS_VariableExists($vid)) {
                    $this->RegisterReference($vid);
                } else {
                    $this->LogDebug(__FUNCTION__, $variable . ' does not exist!');
                    if ($variable == 'PriceVariable') {
                        $this->SetStatus(104);
                        return;
                    }
                }
            }
        }

        // Register all messages
        foreach ($variables as $variable) {
            $vid = $this->ReadPropertyInteger($variable);
            if ($vid >= self::IPS_MIN_ID) {
                if (IPS_VariableExists($vid)) {
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
        }

        // Fill cache data
        $this->CollectDailyValues();

        // Send a complete update message to the display, as parameters may have changed
        /** @phpstan-ignore-next-line */
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());

        // Set status
        $this->SetStatus(102);
    }

    /**
     * The content of the function can be overwritten in order to carry out own reactions to certain messages.
     * The function is only called for registered MessageIDs/SenderIDs combinations.
     *
     * data[0] = new value
     * data[1] = value changed?
     * data[2] = old value
     * data[3] = timestamp.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $sender    Sender ID
     * @param int   $message   ID of the message
     * @param array{0:mixed,1:bool,2:mixed,3:int} $data Data of the message
     * @return void
     */
    public function MessageSink(int $timestamp, int $sender, int $message, array $data): void
    {
        // state changes ?
        if ($data[1] != true) {
            return;
        }

        if ($message === VM_UPDATE) {
            $this->LogDebug(__FUNCTION__, "Update of $sender = $data[0]");
            if ($sender == $this->ReadPropertyInteger('PriceVariable')) {
                $this->CollectDailyValues();
                $result['chartdata'] = $this->ReadCacheArray();
                $result['pricetext'] = $this->ReadPropertyFormatted('PriceVariable');
                $this->UpdateVisualizationValue(json_encode($result));
            } else {
                $result['trendtext'] = $this->ReadPropertyFormatted('TrendVariable');
                $this->UpdateVisualizationValue(json_encode($result));
            }
        }
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed $value The value to be set
     *
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        // Debug output
        $this->LogDebug(__FUNCTION__, $ident . ' => ' . $value);
        // Ident == OnXxxxxYyyyy
        switch ($ident) {
            case 'ChangeStatus':
                break;
            case 'SwitchState':
                break;
            default:
                // Messages from the HTML representation always send the identifier corresponding to the property and,
                // in the value, the difference to be calculated for the variable.
                $vid = $this->ReadPropertyInteger($ident);
                if (!IPS_VariableExists($vid)) {
                    $this->LogDebug(__FUNCTION__, 'Variable to be updated does not exist!');
                    return;
                }
                // Switching the value of the variable
                $new = GetValue($vid);
                RequestAction($vid, !$new);
        }
        // Send a complete update message to the display, as parameters may have changed
        // $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        return;
    }

    /**
     * If the HTML-SDK is to be used, this function must be overwritten in order to return the HTML content.
     *
     * @return string Initial display of a representation via HTML SDK
     */
    public function GetVisualizationTile(): string
    {
        // Add a script to set the values when loading, analogous to changes at runtime
        // Although the return from GetFullUpdateMessage is already JSON-encoded, json_encode is still executed a second time
        // This adds quotation marks to the string and any quotation marks within it are escaped correctly
        $handling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        // Add static HTML from file
        $module = file_get_contents(__DIR__ . '/module.html');
        // Important: $initialHandling at the end, as the handleMessage function is only defined in the HTML
        return $module . $handling;
    }

    /**
     * Generate a message that updates all elements in the HTML display.
     *
     * @return string JSON encoded message information
     */
    private function GetFullUpdateMessage(): string
    {
        // Fill resultset
        $result = [];
        $result['stocktext'] = $this->ReadPropertyString('StockLabel');
        $result['stockfont'] = $this->ReadPropertyInteger('StockFont');
        $result['trendtext'] = $this->ReadPropertyFormatted('TrendVariable');
        $result['trendfont'] = $this->ReadPropertyInteger('TrendFont');
        $result['trendpositive'] = $this->GetColorFormatted($this->ReadPropertyInteger('TrendPositive'));
        $result['trendnegative'] = $this->GetColorFormatted($this->ReadPropertyInteger('TrendNegative'));
        $result['chartline'] = $this->GetColorFormatted($this->ReadPropertyInteger('ChartLine'));
        $result['chartperiod'] = $this->Translate(self::TWSW_MAP_PERIOD[$this->ReadPropertyInteger('ChartData')]);
        $result['chartsmooth'] = $this->ReadPropertyBoolean('ChartSmooth');
        $result['chartfill'] = $this->ReadPropertyBoolean('ChartFill');
        $result['chartoffset'] = $this->ReadPropertyInteger('ChartOffset');
        $result['chartdata'] = $this->ReadCacheArray();
        $result['pricetext'] = $this->ReadPropertyFormatted('PriceVariable');
        $result['pricefont'] = $this->ReadPropertyInteger('PriceFont');
        $this->LogDebug(__FUNCTION__, $result);
        // send it
        return json_encode($result);
    }

    /**
     * Returns the formatted value of a variable defined in the module properties.
     *
     * @param string $property The property name that contains a variable ID.
     * @return string|null The formatted variable value if it exists, otherwise null.
     */
    private function ReadPropertyFormatted(string $property): string|null
    {
        $vid = $this->ReadPropertyInteger($property);
        if (IPS_VariableExists($vid)) {
            return GetValueFormatted($vid);
        }
        return null;
    }

    /**
     * Returns the cached values as a sorted numeric array.
     *
     * The cache is stored in the "DailyCache" buffer as a JSON-encoded
     * associative array where the key is a timestamp or date string and
     * the value is the numeric measurement.
     *
     * This method:
     * - Decodes the buffer JSON into an array
     * - Sorts the entries by key (oldest first)
     * - Returns only the values as a numeric array
     *
     * Example return:
     * [123.45, 125.67, 124.12]
     *
     * @return array<int,float> Array of cached values sorted oldest → newest
     */
    private function ReadCacheArray(): array
    {
        $cacheJson = $this->GetBuffer('DailyCache');
        if ($cacheJson === '') {
            $this->LogDebug(__FUNCTION__, 'Empty cache -> no values!');
            return [];
        }

        $cache = json_decode($cacheJson, true);
        if (!is_array($cache)) {
            $this->LogDebug(__FUNCTION__, 'Wrong cache -> no values!');
            return [];
        }

        // Sortieren nach Timestamp (ältester zuerst)
        ksort($cache);

        // Null-Werte rausfiltern und neu indizieren
        $values = array_values(array_filter($cache, fn ($v) => $v !== null));
        return $values;
    }

    /**
     * Collect all logged archive data from the last x days.
     *
     * @return void
     */
    private function CollectDailyValues(): void
    {
        $days = $this->ReadPropertyInteger('ChartData');
        $buffer = $this->GetBuffer('DailyCache');
        $today = date('Y-m-d');

        $rebuild = false;
        $cache = [];

        // Case 0: ongoing daily values
        if ($days == 1) {
            $this->LogDebug(__FUNCTION__, 'No use cache -> onging daily logged values!');
            $cache = $this->LoadDailyValues();
            $this->SetBuffer('DailyCache', json_encode($cache));
            return;
        }

        if ($buffer !== '') {
            $cache = json_decode($buffer, true);
        }

        // Case 1: empty cache
        if (empty($cache)) {
            $this->LogDebug(__FUNCTION__, 'Cache empty -> complete rebuild!');
            $rebuild = true;
        }

        // Case 2: Property "ChartData" changed
        if (!$rebuild && count($cache) !== $days) {
            $this->LogDebug(__FUNCTION__, 'Days back changed -> rebuild!');
            $rebuild = true;
        }

        // Case 3: change of day
        if (!$rebuild) {
            $lastDate = array_key_last($cache);
            if ($lastDate !== $today) {
                $this->LogDebug(__FUNCTION__, 'Change of day -> update value!');

                // Load current value
                $value = $this->LastDailyValue($today);

                // Remove oldest value, add new value
                array_shift($cache);
                $cache[$today] = $value;

                $this->SetBuffer('DailyCache', json_encode($cache));
                return;
            }
        }

        // Rebuild if needed
        if ($rebuild) {
            $this->LogDebug(__FUNCTION__, 'Rebuild cache for ' . $days . ' days new!');

            $cache = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $cache[$date] = $this->LastDailyValue($date);
            }

            $this->SetBuffer('DailyCache', json_encode($cache));
        }
    }

    /**
     * Retrieves the last logged value for a specific day
     *
     * @param string $date Format: Y-m-d (z.B. "2025-08-20")
     * @return float|null last value or zero if none found (e.g. weekend)
     */
    private function LastDailyValue(string $date): ?float
    {
        $aid = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0];
        $vid = $this->ReadPropertyInteger('PriceVariable');
        if ($vid < self::IPS_MIN_ID) {
            $this->LogDebug(__FUNCTION__, 'No price variable!');
            return null;
        }

        $start = strtotime($date . ' 00:00:00');
        $end = strtotime($date . ' 23:59:59');

        $values = AC_GetLoggedValues($aid, $vid, $start, $end, 1);

        if (count($values) === 0) {
            $this->LogDebug(__FUNCTION__, "No value for $date found!");
            return null; // Weekend / no data
        }

        // Letzten Eintrag nehmen
        $last = end($values);
        $value = (float) $last['Value'];

        $this->LogDebug(__FUNCTION__, "[$date] last value = " . $value);
        return $value;
    }

    /**
     * Retrieves the logged values of the current day
     *
     * @return array<int,float> all logged values of the day
     */
    private function LoadDailyValues(): ?array
    {
        $aid = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0];
        $vid = $this->ReadPropertyInteger('PriceVariable');
        if ($vid < self::IPS_MIN_ID) {
            $this->LogDebug(__FUNCTION__, 'No price variable!');
            return null;
        }

        $lookback = 3; // max 3 days back
        $values = [];
        $days = 0;

        do {
            $start = strtotime("-$days day 00:00");
            $end = strtotime("-$days day 23:59:59");

            $values = AC_GetLoggedValues($aid, $vid, $start, $end, 0);

            if (!empty($values)) {
                $this->LogDebug(__FUNCTION__, "Daily values found on -$days day(s)!");
                break; // Found -> go out
            }
            $days++;
        } while ($days <= $lookback);

        $data = [];
        foreach ($values as $v) {
            $data[$v['TimeStamp']] = $v['Value'];
        }

        if (count($data) == 1) {
            $data = array_merge($data, $data);
        }

        return $data;
    }
}