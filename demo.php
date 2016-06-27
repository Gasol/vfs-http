<?php
require __DIR__ . '/vendor/autoload.php';

use Elastica\Client as Elastica;
use Elastica\Query\MatchAll as MatchAllQuery;
use org\bovigo\vfs\vfsStream as VfsStream;

$content = <<<EOF
{
  "took": 13,
  "timed_out": false,
  "_shards": {
    "total": 5,
    "successful": 5,
    "failed": 0
  },
  "hits": {
    "total": 123,
    "max_score": 1,
    "hits": [
      {
        "_index": "test",
        "_type": "blog",
        "_id": "5458158",
        "_score": 1,
        "_source": {
          "id": 5458158,
          "title": "test blog",
          "popularity": 32131,
          "updated_at": "2016-03-25T02:20:41Z"
        }
      }
    ]
  }
}
EOF;

$paths = [
    'test' => [
        'blog' => [
            '_search' => $content,
        ],
    ],
];
$vfs = VfsStream::setup('localhost', null, $paths);
$config = array(
    'url' => $vfs->url() . '/',
    'transport' => new Stream(),
);

$elastica = new Elastica($config);

$type = $elastica->getIndex('test')->getType('blog');
$resultset = $type->search(new MatchAllQuery());

assert(123 == $resultset->getTotalHits());
assert(1 === count($resultset));
$source = $resultset[0]->getSource();
assert('test blog' === $source['title']);

/**
 * XXX The key wrapper_data in data that return by stream_get_meta_data is be instance of wrapper itself
 * See https://stackoverflow.com/questions/11734592/ for details
 */
// assert(200 == $resultset->getResponse()->getStatus());
