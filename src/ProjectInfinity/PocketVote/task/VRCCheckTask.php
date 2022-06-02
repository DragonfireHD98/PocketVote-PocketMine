<?php

namespace ProjectInfinity\PocketVote\task;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use ProjectInfinity\PocketVote\data\TaskResult;
use ProjectInfinity\PocketVote\event\VoteEvent;
use ProjectInfinity\PocketVote\PocketVote;

class VRCCheckTask extends AsyncTask {

    public $vm, $vrcs, $player, $cert;

    public function __construct(string $player) {
        $this->player = $player;
        $this->vrcs = PocketVote::getPlugin()->getVoteManager()->getVRC();
        $this->cert = PocketVote::$cert;
    }

    public function onRun(): void {
        $results = [];
        foreach($this->vrcs as $vrc) {
            $curl = curl_init(str_replace('{USERNAME}', $this->player, $vrc->check));

            $url = parse_url($vrc->check);

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_PORT => $url['scheme'] === 'https' ? 443 : 80,
                CURLOPT_HEADER => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_USERAGENT => 'PocketVote VRC',
                CURLOPT_CAINFO => $this->cert
            ]);

            $res = curl_exec($curl);

            if($res === false) {
                $results[] = $this->createResult(true, curl_error($curl));
                curl_close($curl);
            } else {

                $result = json_decode($res);

                if(empty($result) || \is_string($result)) {
                    $results[] = $this->createResult(true, 'Failed to parse JSON response from '.$url['host'].'. This is likely a problem with the mentioned site.');
                    $this->setResult($results);
                    curl_close($curl);
                    return;
                }

                if(!isset($result->voted) || !isset($result->claimed)) {
                    $results[] = $this->createResult(true, 'Vote or claim field was missing in response from '.$url['host']);
                    $this->setResult($results);
                    curl_close($curl);
                    return;
                }

                # There is a vote to claim.
                if($result->voted && !$result->claimed) {
                    curl_close($curl);

                    $curl = curl_init(str_replace('{USERNAME}', $this->player, $vrc->claim));

                    $url = parse_url($vrc->check);

                    curl_setopt_array($curl, [
                        CURLOPT_RETURNTRANSFER => 1,
                        CURLOPT_PORT => $url['scheme'] === 'https' ? 443 : 80,
                        CURLOPT_HEADER => false,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                        CURLOPT_CAINFO => $this->cert
                    ]);

                    $res = curl_exec($curl);

                    if($res === false) {
                        $results[] = $this->createResult(true, curl_error($curl));
                        curl_close($curl);
                    } else {

                        $result = json_decode($res);
                        curl_close($curl);

                        if(!isset($result->voted) || !isset($result->claimed)) {
                            $results[] = $this->createResult(true, 'Vote or claim field was missing in response from '.$url['host']);
                            $this->setResult($results);
                            return;
                        }

                        # Claim failed.
                        if($result->voted && !$result->claimed) {
                            $results[] = $this->createResult(true, 'Attempted to claim a vote but it failed. Site: '.$url['host']);
                            $this->setResult($results);
                            return;
                        }

                        # Vote claim succeeded!
                        $results[] = $this->createResult(false, json_decode(json_encode([
                            'success' => true,
                            'payload' => [
                                'player' => $this->player,
                                'ip' => 'unknown',
                                'site' => $url['host']
                            ]
                        ])));

                    }
                }

            }
        }
        $this->setResult($results);
    }

    private function createResult($error, $res) {
        $r = new TaskResult();
        $r->setError($error);
        if($error) {
            $r->setErrorData(['message' => $res]);
        }
        # Had votes.
        if(isset($res->payload) && $res->success) $r->setVotes($res->payload);

        return $r;
    }

    public function onCompletion(): void {
        $server = Server::getInstance();
        if(!$this->hasResult()) {
            $server->getLogger()->emergency('A VRC task finished without a result. This should never happen.');
            return;
        }

        /** @var TaskResult[] $results */
        $results = $this->getResult();

        if(!is_array($results)) {
            $server->getLogger()->warning('VRCCheckTask result was not an array. This is a problem...');
            return;
        }

        foreach($results as $result) {
            if((object) $result->hasError()) {
                $server->getLogger()->error('[PocketVote] VRCCheckTask: An issue occurred, you can ignore this unless it happens often: '.$result->getError()['message']);
                return;
            }
            $vote = (object) $result->getVotes();
            $event = new VoteEvent(PocketVote::getPlugin(), $vote->player, $vote->ip, $vote->site);
            $event->call();
        }

        # Removes task from the array that prevents duplicate tasks.
        PocketVote::getPlugin()->getVoteManager()->removeVRCTask($this->player);
    }
}