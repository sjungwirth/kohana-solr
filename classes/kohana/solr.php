<?php defined('SYSPATH') or die('No direct script access.');
/**
 * @package      Solr
 * @category     Search
 * @author       eHow Team
 * @author       Scott.Jungwirth
 * @copyright    (c) 2011 Demand Media, Inc.
 * @license      MIT
 */
class Kohana_Solr {

	const RESPONSE_WRITER = 'json';

	/**
	 * Named-list treatment constants
	 */
	const NAMED_LIST_FLAT = 'flat';
	const NAMED_LIST_MAP = 'map';
	const NAMED_LIST_ARRARR = 'arrarr';

	/**
	 * Servlet mappings
	 */
	const UPDATE_SERVLET = 'update/json';
	const SEARCH_SERVLET = 'select';

	/**
	 * @var array
	 */
	public static $instances = array();

	/**
	 * Get a singleton Solr instance. If a configuration is not specified,
	 * it will be loaded from the solr configuration file using the same
	 * group as the name.
	 *
	 * // Load the default Solr instance
	 * $solr = Solr::instance();
	 *
	 * // Create a custom configured Solr instance
	 * $solr = Solr::instance('custom', $config);
	 *
	 * @static
	 * @param string $name instance name
	 * @param array $config configuration parameters
	 * @return Solr
	 */
	public static function instance($name = 'default', array $config = NULL)
	{
		if ( ! isset(Solr::$instances[$name]))
		{
			if ($config === NULL)
			{
				$config = Kohana::config('solr')->$name;
			}

			Solr::$instances[$name] = new Solr($name, $config);
		}

		return Solr::$instances[$name];
	}

	/**
	 * Build solr Query string.
	 * Converts array parameters to multiple parameters w/ same name
	 *
	 * @static
	 * @param $params
	 * @return mixed
	 */
	public static function build_query($params)
	{
		// remove brackets from url encoded arrays
		return preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', http_build_query($params, NULL, '&'));
	}

	/**
	 * Escape a value for special query characters such as ':', '(', ')', '*', '?', etc.
	 *
	 * NOTE: inside a phrase fewer characters need escaped, use Solr::escape_phrase() instead
	 *
	 * copied from @link http://code.google.com/p/solr-php-client/
	 *
	 * @param string $value
	 * @return string
	 */
	public static function escape($value)
	{
		// @link http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
		$pattern = '#(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)#';
		$replace = '\\\$1';

		return preg_replace($pattern, $replace, $value);
	}

	/**
	 * Escape a value meant to be contained in a phrase for special query characters
	 *
	 * copied from @link http://code.google.com/p/solr-php-client/
	 *
	 * @param string $value
	 * @return string
	 */
	public static function escape_phrase($value)
	{
		$pattern = '#("|\\\)#';
		$replace = '\\\$1';

		return preg_replace($pattern, $replace, $value);
	}

	/**
	 * Convenience function for creating phrase syntax from a value
	 *
	 * copied from @link http://code.google.com/p/solr-php-client/
	 *
	 * @param string $value
	 * @return string
	 */
	public static function phrase($value)
	{
		return '"' . Solr::escape_phrase($value) . '"';
	}

	/**
	 * @var string
	 */
	public $instance;

	/**
	 * How NamedLists should be formatted in the output. This specifically effects facet counts.
	 *
	 * @var string
	 */
	public $named_list_mode = Solr::NAMED_LIST_MAP;

	/**
	 * @var array
	 */
	public $curl_options;

	/**
	 * @var array
	 */
	protected $config;

	/**
	 * @param string $name
	 * @param array $config
	 */
	protected function __construct($name, array $config)
	{
		$this->instance = $name;

		$base = 'http://'.$config['host'].':'.$config['port'].$config['path'];
		$config['update_url'] = $base.Solr::UPDATE_SERVLET;
		$config['search_url'] = $base.Solr::SEARCH_SERVLET;

		$this->config = $config;
	}

	/**
	 * Add an array of documents to the Solr index all at once
	 *
	 * @param array $documents Should be an array of arrays
	 * @param boolean $overwrite Check for pending documents with the same unique key
	 * @param mixed $commit_within The number of milliseconds that a document must be committed within. If left empty this property will not be set in the request. If boolean value is passed 'commit' will be set to the value
	 * @return array
	 */
	public function batch_index(array $documents, $overwrite = TRUE, $commit_within = FALSE)
	{
		// Filter null values from the documents
		foreach ($documents as & $document) {
			$document = array_filter($document, function($v) {return $v !== NULL; });
		}

		$stream = array(
			'add' => $documents,
		);

		$params = array(
			'overwrite' => $overwrite ? 'true' : 'false',
		);

		if ($commit_within === FALSE || $commit_within === TRUE)
		{
			$params['commit'] = $commit_within ? 'true' : 'false';
		}
		elseif ((int) $commit_within > 0)
		{
			$params['commitWithin'] = (int) $commit_within;
		}

		return $this->request($stream, $params);
	}

	/**
	 * Send a commit command.
	 *
	 * @param boolean $soft_commit
	 * @param boolean $wait_searcher
	 * @param boolean $expunge_deletes
	 * @return array
	 */
	public function commit($soft_commit = FALSE, $wait_searcher = TRUE, $expunge_deletes = FALSE)
	{
		$params = array(
			'softCommit' => $soft_commit ? 'true' : 'false',
			'waitSearcher' => $wait_searcher ? 'true' : 'false',
			'expungeDeletes' => $expunge_deletes ? 'true' : 'false',
		);

		return $this->request(array('commit' => $params));
	}

	/**
	 * Add a document to the Solr index
	 *
	 * @param array $document
	 * @param boolean $overwrite Check for pending documents with the same unique key
	 * @param mixed $commit_within The number of milliseconds that a document must be committed within. If left empty this property will not be set in the request. If boolean value is passed 'commit' will be set to the value
	 * @param float $boost document boost
	 * @return array
	 */
	public function index(array $document, $overwrite = TRUE, $commit_within = FALSE, $boost = NULL)
	{
		// Filter null values from the document
		$document = array_filter($document, function($v) { return $v !== NULL; });

		$stream = array(
			'add' => array(
				'overwrite' => (bool) $overwrite,
				'doc' => $document,
			),
		);

		if ($commit_within === FALSE || $commit_within === TRUE)
		{
			$params['commit'] = $commit_within ? 'true' : 'false';
		}
		elseif ((int) $commit_within > 0)
		{
			$stream['add']['commitWithin'] = (int) $commit_within;
		}

		if ($boost !== NULL)
		{
			$stream['add']['boost'] = (float) $boost;
		}

		return $this->request($stream);
	}

	/**
	 * Send an optimize command.
	 *
	 * @param boolean $soft_commit
	 * @param boolean $wait_searcher
	 * @param int $max_segments
	 * @return array
	 */
	public function optimize($soft_commit = FALSE, $wait_searcher = TRUE, $max_segments = 1)
	{
		$params = array(
			'softCommit' => $soft_commit ? 'true' : 'false',
			'waitSearcher' => $wait_searcher ? 'true' : 'false',
			'maxSegments ' => (int) $max_segments,
		);

		return $this->request(array('optimize' => $params));
	}

	/**
	 * Delete a document based on a query
	 *
	 * @param string $query Expected to be utf-8 encoded
	 * @return array
	 */
	public function remove($query)
	{
		return $this->request(array('delete' => array('query' => (string) $query)));
	}

	/**
	 * Create a delete document based on document ID
	 *
	 * @param string $id Expected to be utf-8 encoded
	 * @return array
	 */
	public function remove_by_id($id)
	{
		return $this->request(array('delete' => array($this->config['unique_key'] => (string) $id)));
	}

	/**
	 * Create and post a delete document based on multiple document IDs.
	 *
	 * @param array $ids Expected to be utf-8 encoded strings
	 * @return array
	 */
	public function remove_by_ids(array $ids)
	{
		$stream = array();
		foreach ($ids as $id)
		{
			$stream[] = json_encode(array('delete' => array($this->config['unique_key'] => (string) $id)));
		}

		return $this->request('{'.implode(',', $stream).'}');
	}

	/**
	 * Send a post request to this Solr Server
	 *
	 * @param string $data
	 * @param array $params
	 * @return array
	 */
	public function request($data, array $params = array())
	{
		if ( ! is_string($data))
		{
			$data = json_encode($data);
		}

		// Ensure json response writer
		Arr::unshift($params, 'wt', Solr::RESPONSE_WRITER);

		// Build request URL
		$url = $this->config['update_url'].'?'.Solr::build_query($params);

		// Setup POST data
		$options = array(
			CURLOPT_POST => TRUE,
			CURLOPT_POSTFIELDS => http_build_query(array('stream.body' => $data), NULL, '&'),
		) + $this->curl_options;

		// Execute response
		$response = Remote::get($url, $options);

		// Return decoded result
		return json_decode($response, TRUE);
	}

	/**
	 * Send a rollback command.
	 *
	 * @return array
	 */
	public function rollback()
	{
		return $this->request(array('rollback' => new stdClass));
	}

	/**
	 * Simple Search interface
	 *
	 * @param string $query The raw query string
	 * @param int $offset The starting offset for result documents
	 * @param int $limit The maximum number of result documents to return
	 * @param array $params key / value pairs for other query parameters (see Solr documentation), use arrays for parameter keys used more than once (e.g. facet.field)
	 * @return array
	 */
	public function search($query, $offset = 0, $limit = 10, array $params = array())
	{
		// common parameters in this interface
		$params = array(
			'wt' => Solr::RESPONSE_WRITER,
			'json.nl' => $this->named_list_mode,
			'q' => $query,
			'start' => $offset,
			'rows' => $limit,
		) + $params;

		// Build request URL
		$url = $this->config['search_url'].'?'.Solr::build_query($params);

		return json_decode(Remote::get($url, $this->curl_options), TRUE);
	}
}
