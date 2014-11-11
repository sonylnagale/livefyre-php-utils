<?php

namespace Livefyre\Core;


use Livefyre\Model\CollectionData;
use Livefyre\Exceptions\ApiException;
use Livefyre\Utils\JWT;
use Livefyre\Routing\Client;
use Livefyre\Api\Domain;
use Livefyre\Utils\LivefyreUtils;
use Livefyre\Validator\CollectionValidator;

class Collection extends Core {
    private $_site;
    private $_data;

    public function __construct(Site $site, CollectionData $data) {
        $this->_site = $site;
        $this->_data = $data;
    }

    public static function init(Site $site, $type, $title, $articleId, $url) {
        $data = new CollectionData($type, $title, $articleId, $url);
        return new Collection($site, CollectionValidator::validate($data));
    }

    public function createOrUpdate() {
        $response = $this->invokeCollectionApi("create");
        if ($response->status_code === 200) {
            $this->getData()->setId(json_decode($response->body)->{"data"}->{"collectionId"});
            return $this;
        } elseif ($response->status_code === 409) {
            $response = $this->invokeCollectionApi("update");

            if ($response->status_code === 200) {
                return $this;
            }
        }
        throw new ApiException($response->status_code);
    }

    public function buildCollectionMetaToken() {
        $collectionMeta = $this->getData()->asArray();

        $issued = $this->isNetworkIssued();
        $core = $issued ? $this->getSite()->getNetwork() : $this->getSite();

        $collectionMeta["iss"] = $core->getUrn();
        return JWT::encode($collectionMeta, $core->getData()->getKey());
    }

    public function buildChecksum() {
        $checksum = $this->getData()->asArray();
        ksort($checksum);
        return md5(str_replace('\/','/',json_encode($checksum)));
    }

    public function getCollectionContent() {
        $url = sprintf("%s/bs3/%s/%s/%s/init",
            Domain::bootstrap($this),
            $this->getSite()->getNetwork()->getData()->getName(),
            $this->getSite()->getData()->getId(),
            base64_encode($this->getData()->getArticleId()));

        $response = Client::GET($url);

        if ($response->status_code >= 400) {
            throw new ApiException($response->status_code);
        }
        return json_decode($response->body);
    }

    private function invokeCollectionApi($method) {
        $uri = sprintf("%s/api/v3.0/site/%s/collection/%s/", Domain::quill($this), $this->getSite()->getData()->getId(), $method);
        $data = json_encode(array(
                "articleId" => $this->getData()->getArticleId(),
                "collectionMeta" => $this->buildCollectionMetaToken(),
                "checksum" => $this->buildChecksum())
        );
        $headers = array(
            "Content-Type" => "application/json",
            "Accepts" => "application/json"
        );

        return Client::POST($uri . "?sync=1", $headers, $data, false);
    }

    public function isNetworkIssued() {
        $topics = $this->getData()->getTopics();
        if (!$topics || count($topics) === 0) {
            return false;
        }

        $urn = $this->getSite()->getNetwork()->getUrn();
        forEach($topics as $topic) {
            $topicId = $topic->getId();
            if (LivefyreUtils::startsWith($topicId, $urn) && !LivefyreUtils::startsWith(str_replace($urn, "", $topicId), ":site=")) {
                return true;
            }
        }
        return false;
    }

    public function getUrn() {
        return $this->_site->getUrn() . ":collection=" . $this->getData()->getId();
    }

    public function getSite() {
        return $this->_site;
    }

    public function setSite($site) {
        $this->_site = $site;
    }

    public function getData() {
        return $this->_data;
    }

    public function setData($data) {
        $this->_data = $data;
    }
}