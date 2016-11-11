<?php

namespace Sebsel\Micropub;

use C;
use Error;
use Files;
use Obj;
use R;
use Remote;
use Response;
use Str;
use Tpl;
use Upload;
use Url;
use Yaml;

class Endpoint {

  const ERROR_FORBIDDEN          = 0;
  const ERROR_INSUFFICIENT_SCOPE = 1;
  const ERROR_INVALID_REQUEST    = 2;

  public function __construct() {

    $endpoint = $this;

    kirby()->routes([
      [
        'pattern' => 'micropub',
        'method'  => 'POST',
        'action'  => function() use($endpoint) {

          try {
            $endpoint->start();
            echo response::success('Yay, new post created', 201);
          } catch (Exception $e) {
            switch($e->getCode()) {
              case Endpoint::ERROR_FORBIDDEN:
                response::json([
                  'error' => 'forbidden',
                  'error_description' => $e->getMessage()
                ], 403);
                break;
              case Endpoint::ERROR_INSUFFICIENT_SCOPE:
                response::json([
                  'error' => 'insufficient_scope',
                  'error_description' => $e->getMessage()
                ], 401);
                break;
              case Endpoint::ERROR_INVALID_REQUEST:
                response::json([
                  'error' => 'invalid_request',
                  'error_description' => $e->getMessage()
                ], 400);
                break;
              default:
                response::json([
                  'error' => 'error',
                  'error_description' => $e->getMessage()
                ], 500);
            }
          }
        }
      ],
      [
        'pattern' => 'micropub',
        'method'  => 'GET',
        'action'  => function() use($endpoint) {

          $token = $endpoint->requireAccessToken();

          if (url::short(url::base($token->me)) != url::short(url::base()))
            response::error('You are not me', Endpoint::ERROR_FORBIDDEN);

          // Publish information about the endpoint
          if (get('q') == 'config')
            response::json([]);

          // Only the syndication targets
          if (get('q') == 'syndicate-to')
            resonse::json([]);
        }
      ]
    ]);
  }

  public function start() {

    $endpoint = $this;

    $token = $endpoint->requireAccessToken();

    if (url::short(url::base($token->me)) != url::short(url::base()))
      throw new Error('You are not me', Endpoint::ERROR_FORBIDDEN);

    if ($data = str::parse(r::body()) and $data['h'] == 'entry') {
      // $data contains the parsed JSON
    } elseif ($data = r::postData() and $data['h'] == 'entry') {
      // $data contains the parsed POST-data
    } else {
      throw new Error('We only accept h-entry as json or x-www-form-urlencoded', Endpoint::ERROR_INVALID_REQUEST);
    }

    // Don't store the access token from POST-requests
    unset($data['access_token']);

    if (!isset($data) or !is_array($data) or count($data) <=1)
      throw new Error('No content was found', Endpoint::ERROR_INVALID_REQUEST);

    $data = $endpoint->fillFields($data);

    $data['token'] = yaml::encode($token->toArray());

    // Set the slug
    if ($data['slug']) $slug = str::slug($data['slug']);
    elseif ($data['name']) $slug = str::slug($data['name']);
    elseif ($data['content']) $slug = str::slug(str::excerpt($data['content'], 50, true, ''));
    elseif ($data['summary']) $slug = str::slug(str::excerpt($data['summary'], 50, true, ''));
    else $slug = time();

    try {
      $newEntry = call(c::get('micropub.page-creator', function($uid, $template, $data) {
        return page('blog')->children()->create($uid, 'article', $data);
      }), [$slug, 'entry', $data]);
    } catch (Exception $e) {
      throw new Error('Post could not be created');
    }

    if (r::files()) {
      $files = $endpoint->handleReceivedFiles($newEntry);
      if ($newEntry->photo()->isNotEmpty())
        $urls = [$newEntry->photo()];
      else $urls = [];
      foreach ($files as $file) $urls[] = $file->url();
      $urls = implode(',', $urls);
      $newEntry->update(['photo' => $urls]);
    }

    return header('Location: '.$newEntry->url(), true, 201);
  }

  private function requireAccessToken($requiredScope=false) {

    // Get 'Authorization: Bearer xxx' from the header or 'access_token=xxx' from the Form-Encoded POST-body
    if(array_key_exists('HTTP_AUTHORIZATION', $_SERVER)
      and preg_match('/Bearer (.+)/', $_SERVER['HTTP_AUTHORIZATION'], $match)) {
      $bearer = $match[1];
    } elseif (isset($_POST['access_token'])) {
      $bearer = get('access_token');
    } else {
      throw new Error('An access token is required. Send an HTTP Authorization header such as \'Authorization: Bearer xxx\' or include a POST-body parameter such as \'access_token=xxx\'', Endpoint::ERROR_FORBIDDEN);
    }

    // Get Token from token endpoint
    $response = remote::get(c::get('micropub.token-endpoint', 'https://tokens.indieauth.com/token'), [
     'headers' => ['Authorization: Bearer '.$match[1]]
    ]);
    parse_str($response->content, $token);
    $token = new Obj($token);

    if($token) {
      // This is where you could add additional validations on specific client_ids. For example
      // to revoke all tokens generated by app 'http://example.com', do something like this:
      // if($token->client_id == 'http://example.com' && strtotime($token->date) <= strtotime('2013-12-21')) // revoked

      // Verify the token has the required scope
      if($requiredScope) {
        if(property_exists($token, 'scope') && in_array($requiredScope, explode(' ', $token->scope))) {
          return $token;
        } else {
          throw new Error('The token provided does not have the necessary scope', Endpoint::ERROR_INSUFFICIENT_SCOPE);
        }
      } else {
        return $token;
      }
    }
  }


  private function fetchUrl($url) {

    $response = remote::get($url);

    // If it is HTML, fetch the Microformats
    if (str::contains($response->headers['Content-Type'], 'html')) {

      require_once(__DIR__ . DS . 'vendor' . DS . 'mf2.php');
      require_once(__DIR__ . DS . 'vendor' . DS . 'comments.php');

      $data   = \Mf2\parse($response->content, $url);
      $result = \IndieWeb\comments\parse($data['items'][0], $url);

      unset($result['type']);

      if(empty($result)) {
        return ['url' => $url];
      }

      return $result;

    // If no HTML, try downloading it as an image
    } elseif (str::contains($response->headers['Content-Type'], 'html')
           or str::contains($response->headers['Content-Type'], 'png')
           or str::contains($response->headers['Content-Type'], 'jpg')
           or str::contains($response->headers['Content-Type'], 'jpeg')
           or str::contains($response->headers['Content-Type'], 'gif')) {

    }

    return $url;
  }

  private function handleReceivedFiles($page) {
    $missingFile = false;
    $files = new Files($page);
    $index = 0;
    do {
      try {
        $upload = new Upload($new->root() . DS . '{safeFilename}', array('input' => 'photo', 'index' => $index));
        if (!$upload->file()) $missingFile = true;
        else $files->append($upload->file());
        $index++;
      } catch(Error $e) {
        switch($e->getCode()) {
          case Upload::ERROR_MISSING_FILE:
            // No more files have been uploaded
            $missingFile = true;
            break;
          default:
            throw new Error($e->getMessage());
        }
      }
    } while(!$missingFile);

    return $files;
  }

  private function fillFields($data) {

    // Rename 'content' to 'text', as to not upset Kirby.
    $data['text'] = $data['content'];
    unset($data['content']);

    // Let's set some things straight, so Kirby can save them.
    foreach ($data as $key => $field) {
      if (is_array($field)) {

        // Check for nestled Microformats object
        if (isset($field[0]['type']) and substr($field[0]['type'], 0, 2) == 'h-' and isset($field[0]['properties']))
          $data[$key] = yaml::encode($field);

        // elseif (is_array(array_values($array)[0]))
        //   $data[$key] = ;

        else
          $data[$key] = implode(',', $field);
      }

      // For all urls, copy the data to the server
      elseif (v::url($field))
        $data[$key] = $endpoint->fetchUrl($data[$key]);
    }

    // Add dates and times
    $data['published'] = strftime('%F %T');
    $data['updated'] = strftime('%F %T');

    return $data;
  }
}