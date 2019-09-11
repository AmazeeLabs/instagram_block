<?php

namespace Drupal\instagram_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;

/**
 * Provides an Instagram block.
 *
 * @Block(
 *   id = "instagram_block_block",
 *   admin_label = @Translation("Instagram block"),
 *   category = @Translation("Social")
 * )
 */
class InstagramBlockBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Constructs a InstagramBlockBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\Client $http_client
   *   The Guzzle HTTP client.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, Client $http_client, ConfigFactory $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'access_token' => '',
      'count' => 4,
      'width' => 150,
      'height' => 150,
      'img_resolution' => 'thumbnail',
      'cache_time_minutes' => 360,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['authorise'] = [
      '#markup' => $this->t('Instagram Block requires connecting to a specific Instagram account. You need to be able to log into that account when asked to. The @help page helps with the setup.', ['@help' => Link::fromTextAndUrl($this->t('Authenticate with Instagram'), Url::fromUri('https://www.drupal.org/node/2746185'))->toString()]),
    ];
    $form['access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access Token'),
      '#description' => $this->t('Your Instagram access token. Eg. 460786509.ab103e5.a54b6834494643588d4217ee986384a8'),
      '#default_value' => $this->configuration['access_token'],
    ];

    $form['count'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of images to display'),
      '#default_value' => $this->configuration['count'],
    ];

    $form['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Image width in pixels'),
      '#default_value' => $this->configuration['width'],
    ];

    $form['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Image height in pixels'),
      '#default_value' => $this->configuration['height'],
    ];

    $image_options = [
      'thumbnail' => $this->t('Thumbnail (150x150)'),
      'low_resolution' => $this->t('Low (320x320)'),
      'standard_resolution' => $this->t('Standard (640x640)'),
    ];

    $form['img_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Image resolution'),
      '#description' => $this->t('Choose the quality of the images you would like to display.'),
      '#default_value' => $this->configuration['img_resolution'],
      '#options' => $image_options,
    ];

    $form['cache_time_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache time in minutes'),
      '#description' => $this->t("Default is 360min = 6 hours. This is important for performance reasons and so the Instagram API limits isn't reached on busy sites."),
      '#default_value' => $this->configuration['cache_time_minutes'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return;
    }
    else {
      $this->configuration['count'] = $form_state->getValue('count');
      $this->configuration['width'] = $form_state->getValue('width');
      $this->configuration['height'] = $form_state->getValue('height');
      $this->configuration['img_resolution'] = $form_state->getValue('img_resolution');
      $this->configuration['cache_time_minutes'] = $form_state->getValue('cache_time_minutes');
      $this->configuration['access_token'] = $form_state->getValue('access_token');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Build a render array to return the Instagram Images.
    $build = [];

    // If no configuration was saved, don't attempt to build block.
    if (empty($this->configuration['access_token'])) {
      // @TODO Display a message instructing user to configure module.
      \Drupal::logger('instagram_block')->error('The configuration for the instagram block was not filled in correctly: User id: @user_id, Access token: @acces_token', array(
        '@user_id' => empty($this->configuration['user_id']) ? 'empty' : $this->configuration['user_id'],
        '@acces_token' => empty($module_config['access_token']) ? '' : $module_config['access_token'],
      ));

      return $build;
    }

    // Build url for http request.
    $uri = "https://api.instagram.com/v1/users/self/media/recent/";
    $options = [
      'query' => [
        'client_id' => '',
        'access_token' => $this->configuration['access_token'],
        'count' => $this->configuration['count'],
      ],
    ];
    $url = Url::fromUri($uri, $options)->toString();

    // Get the instagram images and decode.
    $result = $this->fetchData($url);
    if (!$result || empty($result['data'])) {
      $username = 'unisgmba';
      $insta_source = file_get_contents('http://instagram.com/' . $username);
      $shards = explode('window._sharedData = ', $insta_source);
      $insta_json = explode(';</script>', $shards[1]);
      $results_array = json_decode($insta_json[0], TRUE);
      $url_list = [];
      for ($i = 0; $i < $this->configuration['count']; $i++) {
        if (isset($results_array['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'][$i]['node'])) {
          $image = $results_array['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'][$i]['node'];

          $result['data'][] = [
            'id' => $image['id'],
            'shortcode' => $image['shortcode'],
            'link' => 'https://www.instagram.com/p/' . $image['shortcode'],
            'images' => [
              $this->configuration['img_resolution'] => [
                'url' => $image['display_url'],
              ],
            ],
            'caption' => [
              'text' => $image['edge_media_to_caption']['edges'][0]['node']['text'],
            ],
          ];
        }
      }
    }

    // If still empty return mocked HSG...
    if (!$result || empty($result['data'])) {
      $result['data'] = [
        [
          'id' => 'B2BVJupo72o',
          'shortcode' => 'B2BVJupo72o',
          'link' => 'https://www.instagram.com/p/B2BVJupo72o/',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/28fb489c52255b4af8692497d4f5bf94/5E0CE460/t51.2885-15/sh0.08/e35/s640x640/70367691_2386733384878724_3769865169782033076_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Welcome Week annual tradition: Outdoor Day! FT2020 starting to explore their class team dynamics in the #Swiss Alps.',
          ],
        ],
        [
          'id' => 'B15qNXRItnb',
          'shortcode' => 'B15qNXRItnb',
          'link' => 'https://www.instagram.com/p/BiweaukHdTT',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/9e6f36fe7676bd9699c23f08d956b3ee/5E3D89CE/t51.2885-15/sh0.08/e35/p640x640/70520730_140577727192159_3888400144851672526_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Our Full-time Class of 2020 has arrived on campus! Welcome everyone to the University of St.Gallen MBA.',
          ],
        ],
        [
          'id' => 'B03mpntAnuT',
          'shortcode' => 'B03mpntAnuT',
          'link' => 'https://www.instagram.com/p/B03mpntAnuT',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/0cb6247fbcad7bb940a960e4aef36657/5E01566F/t51.2885-15/sh0.08/e35/s640x640/67566116_117043936288927_4807807865992596671_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Part-time Class of 2021, welcome to the University of St.Gallen!',
          ],
        ],
        [
          'id' => 'B0fnORjC3Gl',
          'shortcode' => 'B0fnORjC3Gl',
          'link' => 'https://www.instagram.com/p/B0fnORjC3Gl',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/f5b72901d86f6adbb4d5e60a12c5d2f4/5DF67574/t51.2885-15/sh0.08/e35/s640x640/66809780_357548941608429_1316955847506125811_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'This year’s Homecoming dinner will be hosted at Lokremise, located in a converted locomotive roundhouse, the largest in Switzerland that still survives from the early 1900s. Register for Homecoming 2019 now using the link in our bio. Lokremise.ch',
          ],
        ],
        [
          'id' => 'B0EIWQMCWTG',
          'shortcode' => 'B0EIWQMCWTG',
          'link' => 'https://www.instagram.com/p/B0EIWQMCWTG',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/17ef83d48be01d4d7feeee62f9937669/5E10315B/t51.2885-15/sh0.08/e35/p750x750/66509590_732150223907365_3023557362297737562_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'We dug into the archives to source some MBA memories from last decade. Did you graduate in the mid-2000s? Refresh your memories and create new ones at Homecoming this year: 28 September in St.Gallen! We’ve updated the format for 2019.',
          ],
        ],
        [
          'id' => 'BzzyPvkixb-',
          'shortcode' => 'BzzyPvkixb-',
          'link' => 'https://www.instagram.com/p/BzzyPvkixb-',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/34137af835025fb0d215d45a182358a2/5DF695E8/t51.2885-15/sh0.08/e35/s750x750/66174354_651741228671788_4773287210034077870_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'In the latest issue of Forbes Switzerland we wanted to highlight the depth of emerging technology experiences we have developed for our MBAs in the past year: a case competition with @sixgroup; electives co-delivered with @microsoftch, #VerumCapital, @ginettateam and other industry experts; company visit to #CryptoFinance AG; and more. Thank you to all our partners for helping further deepen this competence area of our programme.',
          ],
        ],
        [
          'id' => 'Bzx0TNDIvbX',
          'shortcode' => 'Bzx0TNDIvbX',
          'link' => 'https://www.instagram.com/p/Bzx0TNDIvbX',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/54954c4c069757bb74b3674b7540cd0a/5DFD9582/t51.2885-15/sh0.08/e35/p640x640/66482660_129207401635917_3015888903583616945_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Have you read the @the_fintech_times article by Matthias Weissl, co-lecturer of our #Fintech elective? Online and in print now. The print version is available in our MBA reception.',
          ],
        ],
        [
          'id' => 'By5yxRDCiXd',
          'shortcode' => 'By5yxRDCiXd',
          'link' => 'https://www.instagram.com/p/By5yxRDCiXd',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/edc5329b5291c31b1ff0cc499cd04c38/5E093D5E/t51.2885-15/sh0.08/e35/s640x640/62547841_624001864785140_5861506456396632843_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Some of our Women in Business Club, all members of different classes, meeting up in Switzerland ',
          ],
        ],
        [
          'id' => 'ByYjv9Ei9aC',
          'shortcode' => 'ByYjv9Ei9aC',
          'link' => 'https://www.instagram.com/p/ByYjv9Ei9aC',
          'images' => [
            'low_resolution' => [
              'url' => 'https://instagram.fcpt4-1.fna.fbcdn.net/vp/f9234437a87c46b449a709c47ac34283/5E10F04D/t51.2885-15/e35/62148318_2034993386807472_3376290975026198462_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'The Financial Times is conducting a review of its MBA ranking methodology. Today we were invited to their brand new HQ at Bracken House to provide feedback and ideas alongside representatives from other global programmes. Thank you to the Financial Times for creating this open dialogue with business schools as you look at ways to evolve the rankings.',
          ],
        ],

      ];
    }

    foreach ($result['data'] as $post) {
      $build['children'][$post['id']] = [
        '#theme' => 'instagram_block_image',
        '#data' => $post,
        '#href' => $post['link'],
        '#src' => $post['images'][$this->configuration['img_resolution']]['url'],
        '#width' => $this->configuration['width'],
        '#height' => $this->configuration['height'],
      ];
    }

    // Add css.
    if (!empty($build)) {
      $build['#attached']['library'][] = 'instagram_block/instagram_block';
    }

    // Cache for a day.
    $build['#cache']['keys'] = [
      'block',
      'instagram_block',
      $this->configuration['id'],
      $this->configuration['access_token'],
    ];
    $build['#cache']['context'][] = 'languages:language_content';
    $build['#cache']['max-age'] = $this->configuration['cache_time_minutes'] * 60;

    return $build;
  }

  /**
   * Sends a http request to the Instagram API Server.
   *
   * @param string $url
   *   URL for http request.
   *
   * @return bool|mixed
   *   The encoded response containing the instagram images or FALSE.
   */
  protected function fetchData($url) {
    try {
      $response = $this->httpClient->get($url, ['headers' => ['Accept' => 'application/json']]);
      $data = Json::decode($response->getBody());
      if (empty($data)) {
        return FALSE;
      }

      return $data;
    }
    catch (RequestException $e) {
      \Drupal::logger('instagram_block')->error('Status code @status_code: @message', array(
        '@status_code' => $e->getCode(),
        '@message' => $e->getMessage(),
      ));

      return FALSE;
    }
  }

}
