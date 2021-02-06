<?php

namespace Acelle\Plugin\AwsWhitelabel;

use Acelle\Model\Plugin as PluginModel;
use Aws\Route53\Route53Client;
use Aws\Route53Domains\Route53DomainsClient;
use Acelle\Library\Facades\Hook;

class Main
{
    const NAME = 'acelle/aws-whitelabel';
    protected $data;

    public function __construct()
    {
        //
    }

    public function getDbRecord()
    {
        return PluginModel::where('name', self::NAME)->first();
    }

    public function registerHooks()
    {
        // Register hooks
        Hook::register('filter_aws_ses_dns_records', function (&$identity, &$dkims, &$spf) {
            $this->removeAmazonSesBrand($identity, $dkims, $spf);
        });

        // Register hooks
        Hook::register('generate_big_notice_for_sending_server', function ($server) {
            return view('awswhitelabel::notification', [
                'server' => $server,
            ]);
        });

        Hook::register('activate_plugin_'.self::NAME, function () {
            // Run this method as a test
            $this->getRoute53Domains();
        });

        Hook::register('deactivate_plugin_'.self::NAME, function () {
            return true; // or throw an exception
        });

        Hook::register('delete_plugin_'.self::NAME, function () {
            return true; // or throw an exception
        });
    }

    public function removeAmazonSesBrand(&$identity, &$dkims, &$spf)
    {
        $domain = 'acelle.link';
        $identity = null;
        for ($i = 0; $i < sizeof($dkims); $i += 1) {
            $dkim = $dkims[$i];
            $dkim['value'] = str_replace('.dkim.amazonses.com', ".dkim.{$domain}", $dkim['value']);
            $dkims[$i] = $dkim;
        }
        $spf = null;
    }

    public function createCnameRecords($server, $domain, $tokens)
    {
        foreach ($tokens as $subname) {
            $this->changeResourceRecordSets($subname, $domain);
        }
    }

    public function getRoute53Client()
    {
        $data = $this->getDbRecord()->getData();

        if (!array_key_exists('aws_key', $data) || !array_key_exists('aws_secret', $data)) {
            throw new \Exception('Plugin AWS Whitelabel not configured yet');
        }

        $client = self::initRoute53Client($data['aws_key'], $data['aws_secret']);

        return $client;
    }

    public static function initRoute53Client($key, $secret)
    {
        $client = Route53Client::factory(array(
            'credentials' => array(
                'key' => $key,
                'secret' => $secret,
            ),
            'region' => 'us-east-1',
            'version' => '2013-04-01',
        ));

        return $client;
    }

    /*
    public function getRoute53DomainClient($server)
    {
        $client = Route53DomainsClient::factory(array(
            'credentials' => array(
                'key' => trim($server->aws_access_key_id),
                'secret' => trim($server->aws_secret_access_key),
            ),
            'region' => $server->aws_region,
            'version' => '2014-05-15',
        ));

        return $client;
    }
    */

    private function changeResourceRecordSets($subname, $domain)
    {
        // foo.example.com. CNAME foo.amazon.dkim.amazonses.com
        $name = "{$subname}.dkim.{$domain}.";
        $value = "{$subname}.dkim.amazonses.com";
        $hostedZoneId = 'Z01341112AVEFK3WADVQQ';
        $result = $this->getRoute53Client()->changeResourceRecordSets([
            'HostedZoneId' => $hostedZoneId,
            'ChangeBatch' => array(
                'Comment' => 'string',
                'Changes' => array(
                    array(
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => array(
                            'Name' => $name,
                            'Type' => 'CNAME',
                            'TTL' => 600,
                            'ResourceRecords' => array(
                                array(
                                    'Value' => $value,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ]);

        return $result;
    }

    public function getRoute53Domains()
    {
        $results = $this->getRoute53Client()->listHostedZones();
        
        if (!isset($results['HostedZones'])) {
            return [];
        }

        return array_map(
            function ($e) {
                $hostedZone = str_replace('/hostedzone/', '', $e['Id']);
                $name = $e['Name'];
                return [
                    'zone' => $hostedZone,
                    'name' => $name,
                ];
            },
            $results['HostedZones']
        );
    }

    public function testRoute53Connection($keyId, $secret)
    {
        $client = self::initRoute53Client($keyId, $secret);
        $client->listHostedZones();
    }

    public function connectAndSave($keyId, $secret)
    {
        // Test or throw exception
        $this->testRoute53Connection($keyId, $secret);

        // Test OK, proceed
        $record = $this->getDbRecord();
        $record->updateData([
            'aws_key' => $keyId,
            'aws_secret' => $secret,
        ]);
    }

    public function updateDomain($domainAndZone)
    {
        list($domain, $zone) = explode('|', $domainAndZone);
        $record = $this->getDbRecord();
        $record->updateData([
            'domain' => $domain,
            'zone' => $zone,
        ]);
    }

    public function activate()
    {
        $record = $this->getDbRecord();
        $record->activate();
    }
}
