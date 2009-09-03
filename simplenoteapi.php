<?php
/*
 * Simple PHP interface for the Simplenote API (no pun intended)
 * Created by Abraham Vegh
 * http://github.com/abrahamvegh/simplenote-php/
 *
 * USE AT YOUR OWN RISK
 */

class simplenoteapi
{
	private $email;
	private $token;

	/* ***** Internal methods ***** */

	/*
	 * Makes all requests to the API
	 *
	 * This method should only be called by the api_* wrapper methods
	 */
	private function curl_request($url_append, $curl_options = array())
	{
		$curl_options[CURLOPT_URL] = 'https://simple-note.appspot.com/api/' . $url_append;
		$curl_options[CURLOPT_HEADER] = true;
		$curl_options[CURLOPT_RETURNTRANSFER] = true;
		$ch = curl_init();

		curl_setopt_array($ch, $curl_options);

		$result = curl_exec($ch);
		$stats = curl_getinfo($ch);
		$result = explode("\n", $result);
		$headers = array();
		$break = false;
		unset($result[0]);

		foreach ($result as $index => $value)
		{
			if (!$break)
			{
				if (trim($value) == '')
				{
					unset($result[$index]);
					$break = true;
				}
				else
				{
					$line = explode(':', $value, 2);
					$headers[$line[0]] = $line[1];

					unset($result[$index]);
				}
			}
		}

		$result = implode("\n", $result);

		curl_close($ch);

		$result = array(
			'stats' => $stats,
			'headers' => $headers,
			'body' => $result
		);

		return $result;
	}

	/*
	 * Calls the API using a GET
	 */
	private function api_get($method, $parameters = '')
	{
		if (is_array($parameters))
		{
			foreach ($parameters as $key => $value)
			{
				unset($parameters[$key]);
				$parameters[] = urlencode($key) . '=' . urlencode($value);
			}
			$parameters = implode('&', $parameters);
		}

		!empty($parameters) ? $parameters = '?' . $parameters : false ;

		return $this->curl_request($method . $parameters);
	}

	/*
	 * Calls the API using a POST
	 */
	private function api_post($method, $body, $parameters = '')
	{
		$curl_options = array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body
		);

		if (is_array($parameters))
		{
			foreach ($parameters as $key => $value)
			{
				unset($parameters[$key]);
				$parameters[] = urlencode($key) . '=' . urlencode($value);
			}
			$parameters = implode('&', $parameters);
		}

		!empty($parameters) ? $parameters = '?' . $parameters : false ;

		return $this->curl_request($method . $parameters, $curl_options);
	}

	/* ***** Public methods ***** */

	/*
	 * Attempts to authenticate and receive an access token
	 *
	 * Saves the token and returns true if successful
	 * Returns false if it fails for any reason
	 */
	public function login($email, $password)
	{
		$body = base64_encode('email=' . urlencode($email) . '&password=' . urlencode($password));
		$response = $this->api_post('login', $body);

		if ($response['stats']['http_code'] == 200)
		{
			$this->email = $email;
			$this->token = $response['body'];

			return true;
		}
		else
		{
			return false;
		}
	}

	/*
	 * Retrieves the index list
	 *
	 * Returns the data in objects, using the json_decode function
	 * Returns false if it fails for any reason
	 */
	public function index()
	{
		$response = $this->api_get(
			'index',
			array(
				'auth' => $this->token,
				'email' => $this->email
			)
		);

		if ($response['stats']['http_code'] == 200)
		{
			$response = json_decode($response['body']);

			return $response;
		}
		else
		{
			return false;
		}
	}

	/*
	 * Retrieves a note
	 *
	 * Returns an associative array of the data if successful
	 * Returns false if it fails for any reason
	 */
	public function get_note($note_key)
	{
		$response = $this->api_get(
			'note',
			array(
				'key' => $note_key,
				'auth' => $this->token,
				'email' => $this->email,
				'encode' => 'base64'
			)
		);

		if ($response['stats']['http_code'] == 200)
		{
			$note = array(
				'key' => $response['headers']['note-key'],
				'createdate' => $response['headers']['note-createdate'],
				'modifydate' => $response['headers']['note-modifydate'],
				'deleted' => (strtolower($response['headers']['note-deleted']) == 'true') ? true : false ,
				'content' => base64_decode($response['body'])
			);

			return $note;
		}
		else
		{
			return false;
		}
	}

	/*
	 * Updates or creates a note
	 *
	 * Returns the note key if successful
	 * Returns false if it fails for any reason
	 */
	public function save_note($content, $note_key = '')
	{
		$parameters = array(
			'auth' => $this->token,
			'email' => $this->email
		);

		if (isset($note_key)) $parameters['key'] = $note_key;

		$response = $this->api_post(
			'note',
			base64_encode($content),
			$parameters
		);

		if ($response['stats']['http_code'] == 200)
		{
			return $response['body'];
		}
		else
		{
			return false;
		}
	}

	/*
	 * Deletes a note
	 *
	 * Returns true if successful
	 * Returns false is it fails for any reason
	 */
	public function delete_note($note_key)
	{
		$response = $this->api_get(
			'delete',
			array(
				'key' => $note_key,
				'auth' => $this->token,
				'email' => $this->email
			)
		);

		if ($response['stats']['http_code'] == 200)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/*
	 * API-powered search
	 *
	 * Returns data even for zero results
	 * Returns false if it fails for any reason
	 */
	public function search($search_term, $max_results = 10, $offset_index = 0)
	{
		$response = $this->api_get(
			'search',
			array(
				'query' => urlencode($search_term),
				'results' => $max_results,
				'offset' => $offset_index,
				'auth' => $this->token,
				'email' => $this->email
			)
		);

		if ($response['stats']['http_code'] == 200)
		{
			$response = json_decode($response['body']);
			$return = array(
				'count' => $response->Response->totalRecords,
				'results' => array()
			);

			if ($return['count'] > 0)
			{
				foreach ($response->Response->Results as $result)
				{
					if (!empty($result->key)) $return['results'][$result->key] = $result->content;
				}
			}

			return $return;
		}
		else
		{
			return false;
		}
	}
}

$api = new simplenoteapi;
$api->login('email@domain.tld', 'password');
print_r($api->index());