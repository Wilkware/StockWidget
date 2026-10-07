<?php

declare(strict_types=1);

/** General functions */
require_once __DIR__ . '/../libs/_traits.php';

/** Namespaced traits */
use Wilkware\StockWidget\DebugHelper;
use Wilkware\StockWidget\FormatHelper;
use Wilkware\StockWidget\FormHelper;

/**
 *  CLASS StockWidget
 */
class StockWidget extends IPSModuleStrict
{
    // -------------------------------------------------------------------------
    // Traits
    // -------------------------------------------------------------------------

    use DebugHelper;
    use FormatHelper;
    use FormHelper;

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** @var int Min IPS Object ID */
    private const IPS_MIN_ID = 10000;

    /** @var string Archive GUID */
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    /** @var int Max. number of values returned by one AC_GetLoggedValues call */
    private const ARCHIVE_LIMIT = 10000;

    /** @var int Max. number of chart points for the intraday chart */
    private const MAX_POINTS = 200;

    /** @var int Status: price variable does not exist */
    private const STATUS_NO_VARIABLE = 201;

    /** @var int Status: price variable is not logged by the archive */
    private const STATUS_NOT_LOGGED = 202;

    /** @var array<int,string> */
    private const TWSW_MAP_PERIOD = [
        1   => '1 D',
        7   => '1 W',
        30  => '1 M',
        90  => '1 Q',
        180 => '1 H',
        365 => '1 Y'
    ];

    // -------------------------------------------------------------------------
    // Methods
    // -------------------------------------------------------------------------

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting Symcon.
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
     * The function is not called when exiting Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     *
     * @return string Content of the configuration page.
     */
    public function GetConfigurationForm(): string
    {
        // Get Form
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Extract Version
        $instance = IPS_GetInstance($this->InstanceID);
        $modul = IPS_GetModule($instance['ModuleInfo']['ModuleID']);
        $library = IPS_GetLibrary($modul['LibraryID']);
        $version = sprintf('v%s.%d', $library['Version'], $library['Build']);
        $this->ModifyFormElement($form['actions'], 'Version', function (array &$element) use ($version): void
        {
            $element['caption'] = $version;
        });

        return (string) json_encode($form);
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

        // Archive is not ready before the kernel has started -> wait for it
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Register all references and messages
        $variables = ['TrendVariable', 'PriceVariable'];
        foreach ($variables as $variable) {
            $vid = $this->ReadPropertyInteger($variable);
            if ($vid >= self::IPS_MIN_ID) {
                if (IPS_VariableExists($vid)) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                } else {
                    $this->LogDebug(__FUNCTION__, $variable . ' does not exist!');
                    if ($variable == 'PriceVariable') {
                        // Clear old chart data and update the display anyway
                        $this->SetBuffer('DailyCache', '');
                        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
                        $this->SetStatus(self::STATUS_NO_VARIABLE);
                        return;
                    }
                }
            }
        }

        // Reset cache, because the key format differs between the modes (timestamp vs. date)
        $this->SetBuffer('DailyCache', '');

        // Fill cache data
        $this->CollectDailyValues();

        // Send a complete update message to the display, as parameters may have changed
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());

        // Set status
        $vid = $this->ReadPropertyInteger('PriceVariable');
        if ($vid < self::IPS_MIN_ID) {
            $this->SetStatus(IS_INACTIVE);
        } elseif (($aid = $this->GetArchiveID()) === 0 || !AC_GetLoggingStatus($aid, $vid)) {
            $this->LogDebug(__FUNCTION__, 'Price variable is not logged -> no chart data!');
            $this->SetStatus(self::STATUS_NOT_LOGGED);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
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
     * @param array<int,mixed> $data Data of the message
     * @return void
     */
    public function MessageSink(int $timestamp, int $sender, int $message, array $data): void
    {
        switch ($message) {
            case IPS_KERNELSTARTED:
                $this->LogDebug(__FUNCTION__, 'Kernel started -> apply changes!');
                $this->ApplyChanges();
                break;
            case VM_UPDATE:
                // state changes ?
                if (!$data[1]) {
                    return;
                }
                $this->LogDebug(__FUNCTION__, 'Update of ' . $sender . ' = ' . $this->Stringify($data[0]));
                $result = [];
                if ($sender == $this->ReadPropertyInteger('PriceVariable')) {
                    $this->CollectDailyValues();
                    // The archive logs asynchronously -> the new value may not be stored yet
                    $this->UpdateCacheValue((float) $data[0], (int) $data[3]);
                    $result['chartdata'] = $this->ReadCacheArray();
                    $result['pricetext'] = $this->ReadPropertyFormatted('PriceVariable');
                } else {
                    $result['trendtext'] = $this->ReadPropertyFormatted('TrendVariable');
                }
                $this->UpdateVisualizationValue(json_encode($result));
                break;
        }
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
        // Important: $handling at the end, as the handleMessage function is only defined in the HTML
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
        $result['chartperiod'] = $this->Translate(self::TWSW_MAP_PERIOD[$this->ReadPropertyInteger('ChartData')] ?? '');
        $result['chartsmooth'] = $this->ReadPropertyBoolean('ChartSmooth');
        $result['chartfill'] = $this->ReadPropertyBoolean('ChartFill');
        $result['chartoffset'] = $this->ReadPropertyInteger('ChartOffset');
        $result['chartdata'] = $this->ReadCacheArray();
        $result['pricetext'] = $this->ReadPropertyFormatted('PriceVariable');
        $result['pricefont'] = $this->ReadPropertyInteger('PriceFont');
        $this->LogDebug(__FUNCTION__, $result);
        // send it
        return (string) json_encode($result);
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

        // Sort by key (oldest first)
        ksort($cache);

        // Filter out null values and reindex
        return array_values(array_filter($cache, fn ($v) => $v !== null));
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

        // Case 0: ongoing daily values
        if ($days == 1) {
            $this->LogDebug(__FUNCTION__, 'No use cache -> ongoing daily logged values!');
            $this->SetBuffer('DailyCache', json_encode($this->LoadDailyValues()));
            return;
        }

        $cache = [];
        if ($buffer !== '') {
            $cache = json_decode($buffer, true);
            if (!is_array($cache)) {
                $cache = [];
            }
        }

        // Default: complete rebuild of the whole period
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        if (empty($cache)) {
            // Case 1: empty cache
            $this->LogDebug(__FUNCTION__, 'Cache empty -> complete rebuild!');
            $cache = [];
        } elseif (count($cache) !== $days) {
            // Case 2: Property "ChartData" changed
            $this->LogDebug(__FUNCTION__, 'Days back changed -> rebuild!');
            $cache = [];
        } else {
            // Case 3: update of the current day, change of day or gap (e.g. weekend without trading)
            $lastDate = (string) array_key_last($cache);
            $gap = (int) round((strtotime($today) - strtotime($lastDate)) / 86400);
            if ($gap < 0 || $gap >= $days) {
                $this->LogDebug(__FUNCTION__, 'Gap of ' . $gap . ' days -> rebuild!');
                $cache = [];
            } else {
                // Reload from the last collected day (final/closing value) up to today
                $this->LogDebug(__FUNCTION__, 'Update ' . ($gap + 1) . ' day(s) from ' . $lastDate . '!');
                $from = $lastDate;
            }
        }

        // Load last values per day and fill days without values (weekend/holiday -> null)
        $values = $this->LoadLastDailyValues($from, $today);
        for ($ts = strtotime($from); $ts <= strtotime($today); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $cache[$date] = $values[$date] ?? null;
        }

        // Keep only the requested period
        ksort($cache);
        $cache = array_slice($cache, -$days, null, true);

        $this->SetBuffer('DailyCache', json_encode($cache));
    }

    /**
     * Writes the current value into the cache, independent of the archive state.
     *
     * Intraday mode (1 day): the value is appended with its timestamp; on a change of day
     * the cache is restarted, so that no values of the last trading day are mixed in.
     * Otherwise: the value overwrites the entry of its day.
     *
     * @param float $value     Current value of the price variable
     * @param int   $timestamp Timestamp of the update
     * @return void
     */
    private function UpdateCacheValue(float $value, int $timestamp): void
    {
        $cache = json_decode($this->GetBuffer('DailyCache'), true);
        if (!is_array($cache)) {
            $cache = [];
        }

        if ($this->ReadPropertyInteger('ChartData') == 1) {
            $lastKey = array_key_last($cache);
            if ($lastKey !== null && date('Y-m-d', (int) $lastKey) !== date('Y-m-d', $timestamp)) {
                $this->LogDebug(__FUNCTION__, 'New trading day -> restart intraday cache!');
                $cache = [];
            }
            $cache[$timestamp] = $value;
        } else {
            $cache[date('Y-m-d', $timestamp)] = $value;
        }

        $this->SetBuffer('DailyCache', json_encode($cache));
    }

    /**
     * Retrieves the last logged value of every day within a date range.
     *
     * Uses as few archive calls as possible (paging, if more than ARCHIVE_LIMIT values exist).
     *
     * @param string $from First day (Y-m-d)
     * @param string $to   Last day (Y-m-d)
     * @return array<string,float> Last value per day (Y-m-d => value), days without values are missing
     */
    private function LoadLastDailyValues(string $from, string $to): array
    {
        $vid = $this->ReadPropertyInteger('PriceVariable');
        if ($vid < self::IPS_MIN_ID) {
            $this->LogDebug(__FUNCTION__, 'No price variable!');
            return [];
        }
        $aid = $this->GetArchiveID();
        if ($aid === 0) {
            $this->LogDebug(__FUNCTION__, 'No archive instance!');
            return [];
        }

        $start = (int) strtotime($from . ' 00:00:00');
        $end = (int) strtotime($to . ' 23:59:59');

        $result = [];
        $calls = 0;
        do {
            // Values are returned newest first
            $values = AC_GetLoggedValues($aid, $vid, $start, $end, 0);
            $calls++;
            foreach ($values as $v) {
                $date = date('Y-m-d', $v['TimeStamp']);
                // Keep the first (= newest) value per day
                if (!isset($result[$date])) {
                    $result[$date] = (float) $v['Value'];
                }
            }
            if (count($values) < self::ARCHIVE_LIMIT) {
                break;
            }
            // Next page: everything older than the oldest value so far
            $end = (int) end($values)['TimeStamp'] - 1;
        } while ($end >= $start && $calls < 50);

        $this->LogDebug(__FUNCTION__, $from . ' - ' . $to . ': ' . count($result) . ' day(s) with values, ' . $calls . ' archive call(s)');
        return $result;
    }

    /**
     * Retrieves the logged values of the current day (or of the last trading day).
     *
     * @return array<int,float> logged values of the day (timestamp => value), reduced to max. MAX_POINTS
     */
    private function LoadDailyValues(): array
    {
        $vid = $this->ReadPropertyInteger('PriceVariable');
        if ($vid < self::IPS_MIN_ID) {
            $this->LogDebug(__FUNCTION__, 'No price variable!');
            return [];
        }
        $aid = $this->GetArchiveID();
        if ($aid === 0) {
            $this->LogDebug(__FUNCTION__, 'No archive instance!');
            return [];
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
            $data[$v['TimeStamp']] = (float) $v['Value'];
        }
        ksort($data);

        // Reduce to max. MAX_POINTS (keep first and last value)
        $count = count($data);
        if ($count > self::MAX_POINTS) {
            $step = (int) ceil($count / self::MAX_POINTS);
            $keys = array_keys($data);
            $reduced = [];
            foreach ($keys as $i => $key) {
                if ($i % $step === 0 || $i === $count - 1) {
                    $reduced[$key] = $data[$key];
                }
            }
            $this->LogDebug(__FUNCTION__, 'Reduced ' . $count . ' to ' . count($reduced) . ' values');
            $data = $reduced;
        }

        return $data;
    }

    /**
     * Returns the ID of the archive instance.
     *
     * @return int Archive instance ID, 0 if no archive exists
     */
    private function GetArchiveID(): int
    {
        return IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0] ?? 0;
    }
}
