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
      'cache_time_minutes' => 1440,
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
      '#description' => $this->t("Default is 1440 - 24 hours. This is important for performance reasons and so the Instagram API limits isn't reached on busy sites."),
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
          'id' => 'BiyyyQOHQBt',
          'shortcode' => 'BiyyyQOHQBt',
          'link' => 'https://wwww.instagram.com/p/BiyyyQOHQBt',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/cb10f93d7be1b59082a093ea70cea28c/5B95A298/t51.2885-15/e35/31686869_155643895287485_8866883111267336192_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Congratulations to current Part-time MBA Dmitriy Brussintsov (centre) for reaching the finals of the 2018 “INSIDE LVMH” program. He joined up with some students from our SIM programme to take part in this global competition. 📷 ',
          ],
        ],
        [
          'id' => 'BiweaukHdTT',
          'shortcode' => 'BiweaukHdTT',
          'link' => 'https://wwww.instagram.com/p/BiweaukHdTT',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/5176a799b97c484b87003a93fc0f9361/5B9BFEBF/t51.2885-15/e35/31748634_208122266657584_781542455685152768_n.jpg',
            ],
          ],
          'caption' => [
            'text' => '#Zurich, join us tomorrow after work for our #MBA Info Event at Haus zum Rüden. Meet alumni and the team to ask your questions from 18:00 - 21:00. Free registration, link in bio.',
          ],
        ],
        [
          'id' => 'BiW1N0nnbWK',
          'shortcode' => 'BiW1N0nnbWK',
          'link' => 'https://wwww.instagram.com/p/BiW1N0nnbWK',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/6f32db9f4aa58adfe439af05e5800591/5B8FA882/t51.2885-15/e35/31401052_388569301644582_3591806034961760256_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Some of our MBAs did a recent study mission to Singapore 🇸🇬',
          ],
        ],
        [
          'id' => 'BiMHoMcHNhE',
          'shortcode' => 'BiMHoMcHNhE',
          'link' => 'https://wwww.instagram.com/p/BiMHoMcHNhE',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/56123e648bf71fd33ab82f77b6e6b591/5B887613/t51.2885-15/e35/31463316_1706845616071769_342089974713155584_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Another big #thankyou to those who contributed to “Tanzanight” in support of the Full-time MBA charity project! Photo set 2/2.',
          ],
        ],
        [
          'id' => 'BiMESp9nfCZ',
          'shortcode' => 'BiMESp9nfCZ',
          'link' => 'https://wwww.instagram.com/p/BiMESp9nfCZ',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/469f815827618c644b2f3a9c13fa00f2/5B859A05/t51.2885-15/e35/30900040_237201903496744_5893046892228509696_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Thank you to everyone who came out to support our MBAs on Friday for their #fundraiser! Photo set Part 1/2.',
          ],
        ],
        [
          'id' => 'BiFa_jOn_Z7',
          'shortcode' => 'BiFa_jOn_Z7',
          'link' => 'https://wwww.instagram.com/p/BiFa_jOn_Z7',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/241ff938e81f5edeb9997173307fdf38/5B91B4FD/t51.2885-15/e35/30605245_420796315033149_8530602719772147712_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Arjun, from the Class of 2018, showing his skills on the axe 🤘🏼 Last night our Full-time MBAs kicked off their charity #fundraiser with a concert. More concert photos coming soon to our Facebook, Instagram and Tumblr.',
          ],
        ],
        [
          'id' => 'Bh8ej1FnS1W',
          'shortcode' => 'Bh8ej1FnS1W',
          'link' => 'https://wwww.instagram.com/p/Bh8ej1FnS1W',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/5728c0c53beec73bc3ace6277a12bb2e/5B7B00CC/t51.2885-15/e35/30592556_947382698755478_8477859357441130496_n.jpg',
            ],
          ],
          'caption' => [
            'text' => 'Career Service Managers, David O’Connor and Dominique Gobat, delivering an interactive session to our Full-time MBAs yesterday on the topic of #networking, covering both in-person and online strategies.',
          ],
        ],
        [
          'id' => 'Bh6ReH4nEp6',
          'shortcode' => 'Bh6ReH4nEp6',
          'link' => 'https://wwww.instagram.com/p/Bh6ReH4nEp6',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/a41ee72b9382ae3ffb169cf72bc8843d/5B767BA7/t51.2885-15/e35/30591890_2092233024390221_8676976622559035392_n.jpg',
            ],
          ],
          'caption' => [
            'text' => '👏👏Well done to all our MBAs, alumni and their friends and family who came out to represent the University of St.Gallen MBA at this year’s Zurich #Marathon Team Run! 🏃&zwj;♀️🏃',
          ],
        ],
        [
          'id' => 'BhyU_8KHJMW',
          'shortcode' => 'BhyU_8KHJMW',
          'link' => 'https://wwww.instagram.com/p/BhyU_8KHJMW',
          'images' => [
            'low_resolution' => [
              'url' => 'https://scontent-frt3-2.cdninstagram.com/vp/dc5ee7a01ee6f5035a96d6899869baca/5B770F7F/t51.2885-15/e35/30841906_191888794939666_8667454967826612224_n.jpg',
            ],
          ],
          'caption' => [
            'text' => '⏱The new #light installation on the entrance of St.Gallen’s train station is a clock. Have you deciphered it yet?',
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
