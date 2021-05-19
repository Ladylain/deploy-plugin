<?php namespace RainLab\Deploy\Models;

use Http;
use Model;
use ValidationException;
use Exception;

/**
 * Server Model
 */
class Server extends Model
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string table associated with the model
     */
    public $table = 'rainlab_deploy_servers';

    /**
     * @var array rules for validation
     */
    public $rules = [
        'server_name' => 'required',
        'endpoint_url' => 'required'
    ];

    /**
     * @var array jsonable attribute names that are json encoded and decoded from the database
     */
    protected $jsonable = ['deploy_preferences'];

    /**
     * @var array hasOne and other relations
     */
    public $hasOne = [
        'key' => ServerKey::class
    ];

    /**
     * beforeValidate event
     */
    public function beforeValidate()
    {
        $this->processLocalPrivateKey();
    }

    /**
     * transmitArtisan command to the server
     */
    public function transmitArtisan($command): array
    {
        return $this->transmit('artisanCommand', ['artisan' => $command]);
    }

    /**
     * transmitScript to execute on the server
     */
    public function transmitScript($scriptName): array
    {
        $scriptPath = plugins_path("rainlab/deploy/beacon/scripts/${scriptName}.txt");

        $scriptContents = base64_encode(file_get_contents($scriptPath));

        return $this->transmit('evalScript', ['script' => $scriptContents]);
    }

    /**
     * transmitFile to the server
     */
    public function transmitFile(string $filePath, array $params = []): array
    {
        $response = Http::post($this->buildUrl('fileUpload', $params), function($http) use ($filePath) {
            $http->dataFile('file', $filePath);
            $http->data('filename', md5($filePath));
            $http->data('filehash', md5_file($filePath));
        });

        return $this->processTransmitResponse($response);
    }

    /**
     * transmit data to the server
     */
    public function transmit(string $cmd, array $params = []): array
    {
        $response = Http::get($this->buildUrl($cmd, $params));

        return $this->processTransmitResponse($response);
    }

    /**
     * processTransmitResponse handles the beacon response
     */
    protected function processTransmitResponse($response)
    {
        if ($response->code !== 201) {
            throw new Exception('Invalid response from Beacon');
        }

        $body = json_decode($response->body, true);

        if ($response->code === 400) {
            throw new Exception($body['error'] ?? 'Unspecified error from beacon');
        }

        if (!is_array($body)) {
            throw new Exception('Invalid object from Beacon');
        }

        return $body;
    }

    /**
     * buildUrl for the beacon with GET vars
     */
    protected function buildUrl(string $cmd, array $params = []): string
    {
        return $this->endpoint_url . '?' . http_build_query($this->preparePayload($cmd, $params));
    }

    /**
     * preparePayload for the beacon to process
     */
    protected function preparePayload(string $cmd, array $params = []): array
    {
        $key = $this->key;

        $params['cmd'] = $cmd;
        $params['nonce'] = $this->createNonce();

        $data = base64_encode(json_encode($params));

        $toSend = [
            'X_OCTOBER_BEACON' => $key->keyId(),
            'X_OCTOBER_BEACON_PAYLOAD' => $data,
            'X_OCTOBER_BEACON_SIGNATURE' => $key->signData($data)
        ];

        return $toSend;
    }

    /**
     * createNonce based on millisecond time
     */
    protected function createNonce(): int
    {
        $mt = explode(' ', microtime());
        return $mt[1] . substr($mt[0], 2, 6);
    }

    /**
     * processLocalPrivateKey will check the private_key attribute locally
     * validate it and transform to a related model
     */
    protected function processLocalPrivateKey(): void
    {
        try {
            if (!strlen(trim($this->private_key))) {
                throw new ValidationException(['private_key' => 'Deployment Key is a required field']);
            }

            // Validate key value
            $serverKey = new ServerKey;
            $serverKey->privkey = $this->private_key;
            $serverKey->validatePrivateKey();

            // Set key relationship instead of attribute
            unset($this->private_key);
            $this->key = $serverKey;
        }
        catch (Exception $ex) {
            throw new ValidationException(['private_key' => $ex->getMessage()]);
        }
    }

    /**
     * getPluginsOptions returns an array of available plugins to deploy
     */
    public function getPluginsOptions(): array
    {
        return \System\Models\PluginVersion::all()->lists('code', 'code');
    }

    /**
     * getThemesOptions returns an array of available themes to deploy
     */
    public function getThemesOptions(): array
    {
        $result = [];

        foreach (\Cms\Classes\Theme::all() as $theme) {
            if ($theme->isLocked()) {
                $label = $theme->getConfigValue('name').' ('.$theme->getDirName().'*)';
            }
            else {
                $label = $theme->getConfigValue('name').' ('.$theme->getDirName().')';
            }

            $result[$theme->getDirName()] = $label;
        }

        return $result;
    }
}