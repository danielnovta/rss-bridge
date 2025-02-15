<?php

class SpotifyBridge extends BridgeAbstract
{
    const NAME = 'Spotify';
    const URI = 'https://spotify.com/';
    const DESCRIPTION = 'Fetches the latest items from one or more artists, playlists or podcasts';
    const MAINTAINER = 'Paroleen';
    const CACHE_TIMEOUT = 3600;
    const PARAMETERS = [ [
        'clientid' => [
            'name' => 'Client ID',
            'type' => 'text',
            'required' => true
        ],
        'clientsecret' => [
            'name' => 'Client secret',
            'type' => 'text',
            'required' => true
        ],
        'country' => [
            'name' => 'Country/Market',
            'type' => 'text',
            'required' => false,
            'exampleValue' => 'US',
            'defaultValue' => 'US'
        ],
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'required' => false,
            'exampleValue' => 10,
            'defaultValue' => 10
        ],
        'spotifyuri' => [
            'name' => 'Spotify URIs',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'spotify:artist:4lianjyuR1tqf6oUX8kjrZ [,spotify:playlist:37i9dQZF1DXcBWIGoYBM5M,spotify:show:6ShFMYxeDNMo15COLObDvC]',
        ],
        'albumtype' => [
            'name' => 'Album type',
            'type' => 'text',
            'required' => false,
            'exampleValue' => 'album,single,appears_on,compilation',
            'defaultValue' => 'album,single'
        ]
    ] ];

    const TOKEN_URI = 'https://accounts.spotify.com/api/token';
    const API_URI = 'https://api.spotify.com/v1/';

    const TYPE_ALBUM = 'artist';
    const TYPE_PLAYLIST = 'playlist';
    const TYPE_PODCAST = 'show';

    const ENTRY_ALBUM = 'album';
    const ENTRY_PLAYLIST = 'track';
    const ENTRY_PODCAST = 'episode';

    const NOT_SUPPORTED = 'Spotify URI not supported';

    private $uri = '';
    private $name = '';
    private $token = '';
    private $uris = [];
    private $entries = [];

    public function getURI()
    {
        if (empty($this->uri)) {
            $this->getFirstEntry();
        }

        return $this->uri;
    }

    public function getName()
    {
        if (empty($this->name)) {
            $this->getFirstEntry();
        }

        return $this->name;
    }

    public function getIcon()
    {
        return 'https://www.scdn.co/i/_global/favicon.png';
    }

    private function getUriType($uri)
    {
        return explode(':', $uri)[1];
    }

    private function getId($uri)
    {
        return explode(':', $uri)[2];
    }

    private function getEntryType($type)
    {
        $entry_types = [
            self::TYPE_ALBUM => self::ENTRY_ALBUM,
            self::TYPE_PLAYLIST => self::ENTRY_PLAYLIST,
            self::TYPE_PODCAST => self::ENTRY_PODCAST,
        ];

        if (isset($entry_types[$type])) {
            return $entry_types[$type];
        } else {
            throw new \Exception(self::NOT_SUPPORTED);
        }
    }

    private function getDate($entry)
    {
        if (isset($entry['type'])) {
            $type = 'release_date';
        } else {
            $type = 'added_at';
        }

        $date = $entry[$type];

        if (strlen($date) == 4) {
            $date .= '-01-01';
        } elseif (strlen($date) == 7) {
            $date .= '-01';
        }

        if (strlen($date) > 10) {
            return DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $date)->getTimestamp();
        }

        return DateTime::createFromFormat('Y-m-d', $date)->getTimestamp();
    }

    private function getAlbumType()
    {
        return $this->getInput('albumtype');
    }

    private function getCountry()
    {
        return $this->getInput('country');
    }

    private function getToken()
    {
        $cacheFactory = new CacheFactory();

        $cache = $cacheFactory->create();
        $cache->setScope('SpotifyBridge');
        $cache->setKey(['token']);

        if ($cache->getTime()) {
            $time = (new DateTime())->getTimestamp() - $cache->getTime();
            Debug::log('Token time: ' . $time);
        }

        if ($cache->getTime() == false || $time >= 3600) {
            Debug::log('Fetching token from Spotify');
            $this->fetchToken();
            $cache->saveData($this->token);
        } else {
            Debug::log('Loading token from cache');
            $this->token = $cache->loadData();
        }

        Debug::log('Token: ' . $this->token);
    }

    private function getFirstEntry()
    {
        if (!is_null($this->getInput('spotifyuri')) && strpos($this->getInput('spotifyuri'), ',') === false) {
            $type = $this->getUriType($this->uris[0]);
            $uri = self::API_URI . $type . 's/' . $this->getId($this->uris[0]);

            if ($type === self::TYPE_PODCAST) {
                $uri = $uri . '?market=' . $this->getCountry();
            }

            $item = $this->fetchContent($uri);

            $this->uri = $item['external_urls']['spotify'];
            $this->name = $item['name'] . ' - Spotify';
        } else {
            $this->uri = parent::getURI();
            $this->name = parent::getName();
        }
    }

    private function getAllUris()
    {
        Debug::log('Parsing all uris');
        $this->uris = explode(',', $this->getInput('spotifyuri'));
    }

    private function getAllEntries()
    {
        $this->entries = [];

        $this->getAllUris();

        Debug::log('Fetching all entries');
        foreach ($this->uris as $uri) {
            $type = $this->getUriType($uri);
            $entry_type = $this->getEntryType($type);
            $fetch = true;
            $offset = 0;

            $api_url = self::API_URI . $type . 's/'
                . $this->getId($uri)
                . '/' . $entry_type
                . 's?limit=50';

            if ($type === self::TYPE_ALBUM) {
                $api_url = $api_url . '&country=' . $this->getCountry() . '&include_groups=' . $this->getAlbumType();
            } else {
                $api_url = $api_url . '&market=' . $this->getCountry();
            }

            while ($fetch) {
                $partial = $this->fetchContent($api_url
                    . '&offset='
                    . $offset);

                if (!empty($partial['items'])) {
                    $this->entries = array_merge(
                        $this->entries,
                        $partial['items']
                    );
                } else {
                    $fetch = false;
                }

                $offset += 50;
            }
        }
    }

    private function fetchToken()
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, self::TOKEN_URI);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic '
            . base64_encode($this->getInput('clientid')
            . ':'
            . $this->getInput('clientsecret'))]);

        $json = curl_exec($curl);
        $json = json_decode($json)->access_token;
        curl_close($curl);

        $this->token = $json;
    }

    private function fetchContent($url)
    {
        $this->getToken();
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Bearer '
            . $this->token]);

        Debug::log('Fetching content from ' . $url);
        $json = curl_exec($curl);
        $json = json_decode($json, true);
        curl_close($curl);

        return $json;
    }

    private function sortEntries()
    {
        Debug::log('Sorting entries');
        usort($this->entries, function ($entry1, $entry2) {
            if ($this->getDate($entry1) < $this->getDate($entry2)) {
                return 1;
            } else {
                return -1;
            }
        });
    }

    private function getAlbumData($album)
    {
        $item = [];
        $item['title'] = $album['name'];
        $item['uri'] = $album['external_urls']['spotify'];

        $item['timestamp'] = $this->getDate($album);
        $item['author'] = $album['artists'][0]['name'];
        $item['categories'] = [$album['album_type']];

        $item['content'] = '<img style="width: 256px" src="'
            . $album['images'][0]['url']
            . '">';

        if ($album['total_tracks'] > 1) {
            $item['content'] .= '<p>Total tracks: '
                . $album['total_tracks']
                . '</p>';
        }

        return $item;
    }

    private function getTrackData($track)
    {
        $item = [];

        $item['title'] = $track['track']['name'];
        $item['uri'] = $track['track']['external_urls']['spotify'];

        $item['timestamp'] = $this->getDate($track);
        $item['author'] = $track['track']['artists'][0]['name'];
        $item['categories'] = ['track'];

        $item['content'] = '<img style="width: 256px" src="'
            . $track['track']['album']['images'][0]['url']
            . '">';

        return $item;
    }

    private function getEpisodeData($episode)
    {
        $item = [];

        $item['title'] = $episode['name'];
        $item['uri'] = $episode['external_urls']['spotify'];
        $item['timestamp'] = $this->getDate($episode);

        $item['content'] = '<img style="width: 256px" src="'
            . $episode['images'][0]['url']
            . '">';

        if (isset($episode['description'])) {
            $item['content'] = $item['content'] . '<p>'
                . $episode['description']
                . '</p>';
        }

        if (isset($episode['audio_preview_url'])) {
            $item['content'] = $item['content'] . '<audio controls src="'
                . $episode['audio_preview_url']
                . '"></audio>';
        }

        return $item;
    }

    public function collectData()
    {
        $offset = 0;

        $this->getAllEntries();
        $this->sortEntries();

        Debug::log('Building RSS feed');
        foreach ($this->entries as $entry) {
            if (! isset($entry['type'])) {
                $item = $this->getTrackData($entry);
            } else if ($entry['type'] === self::ENTRY_ALBUM) {
                $item = $this->getAlbumData($entry);
            } else if ($entry['type'] === self::ENTRY_PODCAST) {
                $item = $this->getEpisodeData($entry);
            } else {
                throw new \Exception(self::NOT_SUPPORTED);
            }

            $this->items[] = $item;

            if ($this->getInput('limit') > 0 && count($this->items) >= $this->getInput('limit')) {
                break;
            }
        }
    }
}
