<?php

namespace App\Client;

use App\Repository\PlatformRepository;
use App\Symbol\IconResolver;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheItemPoolInterface;

class NodeClient
{
    private ClientInterface $client;
    private string $baseUrl;
    private PlatformRepository $platformRepository;
    private CacheItemPoolInterface $cacheItemPool;
    private IconResolver $iconResolver;

    public function __construct(ClientInterface $client,
        PlatformRepository $platformRepository,
        CacheItemPoolInterface $cacheItemPool,
        IconResolver $iconResolver,
        string $baseUrl
    ) {
        $this->client = $client;
        $this->baseUrl = $baseUrl;
        $this->platformRepository = $platformRepository;
        $this->cacheItemPool = $cacheItemPool;
        $this->iconResolver = $iconResolver;
    }

    public function getFarms(): array
    {
        $cache = $this->cacheItemPool->getItem('farms');

        if ($cache->isHit()) {
            return $cache->get();
        }

        $farms = [];

        try {
            $farms = json_decode($this->client->request('GET', $this->baseUrl . '/farms')->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
        }

        $cache->expiresAfter(60 * 5)->set($farms);
        $this->cacheItemPool->save($cache);

        return $farms;
    }

    public function getPrices(): array
    {
        $cache = $this->cacheItemPool->getItem('price');

        if ($cache->isHit()) {
            return $cache->get();
        }

        try {
            $prices = json_decode($this->client->request('GET', $this->baseUrl . '/prices')->getBody()->getContents(), true);

            $cache->expiresAfter(60 * 3)->set($prices);
            $this->cacheItemPool->save($cache);

            return $prices;
        } catch (GuzzleException $e) {
        }

        return [];
    }

    public function getTokens(): array
    {
        $cache = $this->cacheItemPool->getItem('tokens');

        if ($cache->isHit()) {
            return $cache->get();
        }

        try {
            $content = json_decode($this->client->request('GET', $this->baseUrl . '/tokens')->getBody()->getContents(), true);

            $cache->expiresAfter(60 * 3)->set($content);
            $this->cacheItemPool->save($cache);

            return $content;
        } catch (GuzzleException $e) {
        }

        return [];
    }

    public function getLiquidityTokens(): array
    {
        $cache = $this->cacheItemPool->getItem('liquidity-tokens');

        if ($cache->isHit()) {
            return $cache->get();
        }

        try {
            $content = json_decode($this->client->request('GET', $this->baseUrl . '/liquidity-tokens')->getBody()->getContents(), true);

            $cache->expiresAfter(60 * 3)->set($content);
            $this->cacheItemPool->save($cache);

            return $content;
        } catch (GuzzleException $e) {
        }

        return [];
    }

    public function getBalances(string $address): array
    {
        $cache = $this->cacheItemPool->getItem('balances-' . $address);

        if ($cache->isHit()) {
            return $cache->get();
        }

        try {
            $balances = json_decode($this->client->request('GET', $this->baseUrl . '/balances/' . $address)->getBody()->getContents(), true);

            $cache->expiresAfter(60 * 5)->set($balances);
            $this->cacheItemPool->save($cache);

            return $balances;
        } catch (GuzzleException $e) {
        }

        return [];
    }

    public function getAddressFarms(string $address): array
    {
        $cache = $this->cacheItemPool->getItem('farms-v4-' . $address);
        if ($cache->isHit()) {
            return $cache->get();
        }

        $prices = $this->getPrices();

        try {
            $content = $this->client->request('GET', $this->baseUrl . '/all/yield/' . $address, [
                'timeout' => 20,
            ]);
        } catch (GuzzleException $e) {
            return [];
        }

        $body = json_decode($content->getBody()->getContents(), true);

        $balances = [];
        foreach (($body['balances'] ?? []) as $balance) {
            $balances[$balance['token']] = $balance;
        }

        $content = $body['platforms'];

        $result = [];
        foreach ($content as $platform => $farms) {
            $result[$platform] = $this->platformRepository->getPlatform($platform);

            if (isset($prices[$result[$platform]['token']])) {
                $result[$platform]['token_price'] = $prices[$result[$platform]['token']];
            }

            $result[$platform]['name'] = $platform;
            $result[$platform]['farms'] = $this->formatFarms($farms);

            $result[$platform]['rewards'] = $this->getTotalRewards($farms);

            $result[$platform]['rewards_total'] = array_sum(array_map(static function (array $obj) {
                return $obj['usd'] ?? 0;
            }, $result[$platform]['rewards'] ?? []));

            $result[$platform]['usd'] = $this->getUsd($farms);

            if (isset($result[$platform]['token'])) {
                $token = $result[$platform]['token'];

                if (isset($balances[$token])) {
                    $result[$platform]['wallet'] = $balances[$token];
                }
            }
        }

        uasort($result, function ($a, $b) {
            return ($b['usd'] ?? 0) + ($b['rewards_total'] ?? 0) <=> ($a['usd'] ?? 0) + ($a['rewards_total'] ?? 0);
        });

        $farms = array_values($result);

        $usdTotal = 0.0;
        foreach($farms as $platform1) {
            $usdTotal += ($platform1['usd'] ?? 0.0);
        }

        $usdPending = 0.0;
        foreach ($farms as $platform1) {
            foreach ($platform1['farms'] as $farm) {
                foreach ($farm['rewards'] ?? [] as $reward) {
                    $usdPending += ($reward['usd'] ?? 0.0);
                }
            }
        }

        $wallet = $body['wallet'] ?? [];
        $liquidityPools = $body['liquidityPools'] ?? [];

        $summary = array_filter([
            'wallet' => array_sum(array_map(fn($i) => $i['usd'] ?? 0.0, $wallet)),
            'liquidityPools' => array_sum(array_map(fn($i) => $i['usd'] ?? 0.0, $liquidityPools)),
            'rewards' => $usdPending,
            'vaults' => $usdTotal,
        ]);

        $summary['total'] = array_sum(array_values($summary));
        asort($summary);

        $tokens = [...$wallet, ...$liquidityPools];
        usort($tokens, function ($a, $b) {
            return ($b['usd'] ?? 0) <=> ($a['usd'] ?? 0);
        });

        $values = [
            'wallet' => $tokens,
            'farms' => array_values($result),
            'summary' => $summary,
        ];

        $cache->set($values)->expiresAfter(10);

        $this->cacheItemPool->save($cache);

        return $values;
    }

    /**
     * @param $farms1
     * @return array
     */
    private function getTotalRewards($farms1): array
    {
        $allRewards = [];

        foreach ($farms1 as $farm) {
            foreach ($farm['rewards'] ?? [] as $reward) {
                if (!isset($allRewards[$reward['symbol']])) {
                    $allRewards[$reward['symbol']] = [
                        'symbol' => $reward['symbol'],
                        'amount' => 0,
                        'usd' => 0,
                    ];
                }

                $allRewards[$reward['symbol']]['amount'] += $reward['amount'] ?? 0;
                $allRewards[$reward['symbol']]['usd'] += $reward['usd'] ?? 0;
            }
        }

        $allRewards = array_values($allRewards);

        uasort($allRewards, function($a, $b) {
            return $b['usd'] <=> $a['usd'];
        });

        return array_slice($allRewards, 0, 5);
    }

    private function getUsd(array $farms): float
    {
        $usd = 0.0;

        foreach ($farms as $farm) {
            $usd += ($farm['deposit']['usd'] ?? 0.0);
        }

        return $usd;
    }

    private function formatFarms(array $farms): array
    {
        foreach ($farms as $key => $farm) {
            $token = $farm['farm']['name'];

            if (isset($farm['farm']['token'])) {
                $token = $farm['farm']['token'];
            }

            $farms[$key]['icon'] = $this->iconResolver->getIcon($token);

            $farms[$key]['farm_rewards'] = array_sum(array_map(static function (array $obj) {
                return $obj['usd'] ?? 0;
            }, $farm['rewards'] ?? []));
        }

        uasort($farms, function($a, $b) {
            $bV = ($b['deposit']['usd'] ?? 0) + ($b['farm_rewards'] ?? 0);
            $aV = ($a['deposit']['usd'] ?? 0) + ($a['farm_rewards'] ?? 0);

            return $bV <=> $aV;
        });

        return array_values($farms);
    }

    public function getDetails(string $address, string $farmId): array
    {
        $cache = $this->cacheItemPool->getItem('farms-details-' . md5($address . $farmId));

        if ($cache->isHit()) {
            return $cache->get();
        }

        try {
            $content = $this->client->request('GET', $this->baseUrl . '/details/' . $address . '/' . $farmId, [
                'timeout' => 25,
            ]);
        } catch (GuzzleException $e) {
            return [];
        }

        $result = json_decode($content->getBody()->getContents(), true);

        $this->cacheItemPool->save($cache->set($result)->expiresAfter(60 * 5));

        return $result;
    }
}