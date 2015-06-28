<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\I18n;

use Spiral\Core\Component;
use Spiral\Core\ConfiguratorInterface;
use Spiral\Core\RuntimeCacheInterface;
use Spiral\Helpers\StringHelper;

class Translator extends Component
{
    /**
     * Will provide us helper method getInstance().
     */
    use Component\SingletonTrait, Component\ConfigurableTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = __CLASS__;

    /**
     * Bundle to use for short localization syntax (l function).
     */
    const DEFAULT_BUNDLE = 'default';

    /**
     * Models and other classes which inherits I18nIndexable interface allowed to be automatically
     * parsed and analyzed for messages stored in default property values (static and non static),
     * such values can be prepended and appended with i18n prefixes ([[ and ]] by default) and will
     * be localized on output.
     *
     * Class should implement i18nNamespace method (static) which will define required i18n namespace.
     */
    const I18N_PREFIX  = '[[';
    const I18N_POSTFIX = ']]';

    /**
     * Constructed language pluralizers.
     *
     * @var PluralizerInterface[]
     */
    protected $pluralizers = array();

    /**
     * Currently selected language identifier.
     *
     * @var string
     */
    protected $language = '';

    /**
     * Options associated with currently active language, define pluralization formula, word forms
     * count and  bundles directory.
     *
     * @var array
     */
    protected $languageOptions = array();

    /**
     * Already loaded language bundles, bundle define list of associations between primary and
     * currently selected language. Bundles can be also used for "internal translating" (en => en).
     *
     * @var array
     */
    protected $bundles = array();

    /**
     * Core component.
     *
     * @var RuntimeCacheInterface
     */
    protected $runtime = null;

    /**
     * New I18nManager component instance, while construing default language and timezone will be
     * mounted.
     *
     * @param ConfiguratorInterface $configurator
     * @param RuntimeCacheInterface $runtime
     */
    public function __construct(ConfiguratorInterface $configurator, RuntimeCacheInterface $runtime)
    {
        $this->runtime = $runtime;
        $this->config = $configurator->getConfig('i18n');

        $this->language = $this->config['default'];
        $this->languageOptions = $this->config['languages'][$this->language];
    }

    /**
     * Change application language selection, all future translations or pluralization
     * phrases will be fetched using new language options and bundles.
     *
     * @param string $language Valid language identifier (en, ru, de).
     * @throws I18nException
     */
    public function setLanguage($language)
    {
        if (!isset($this->config['languages'][$language]))
        {
            throw new I18nException("Invalid language '{$language}', no presets found.");
        }

        //Cleaning all bundles
        $this->bundles = array();

        $this->language = $language;
        $this->languageOptions = $this->config['languages'][$language];
    }

    /**
     * Currently selected language identifier.
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Get language specific pluralizer.
     *
     * @param string $language If empty current language pluralizer will be returned.
     * @return PluralizerInterface
     */
    public function getPluralizer($language = '')
    {
        if (empty($language))
        {
            $language = $this->language;
        }

        if (isset($this->pluralizers[$language]))
        {
            return $this->pluralizers[$language];
        }

        $pluralizer = $this->config['languages'][$language]['pluralizer'];

        return $this->pluralizers[$language] = new $pluralizer;
    }

    /**
     * Load i18n bundle content to memory specific to currently selected language.
     *
     * @param string $bundle
     */
    protected function loadBundle($bundle)
    {
        if (isset($this->bundles[$bundle]))
        {
            return;
        }

        $this->bundles[$bundle] = $this->runtime->loadData(
            $bundle,
            $this->languageOptions['dataFolder']
        );

        if (empty($this->bundles[$bundle]))
        {
            $this->bundles[$bundle] = array();
        }
    }

    /**
     * Save modified i18n bundle to language specific directory.
     *
     * @param string $bundle
     */
    protected function saveBundle($bundle)
    {
        if (!isset($this->bundles[$bundle]))
        {
            return;
        }

        $this->runtime->saveData(
            $bundle,
            $this->bundles[$bundle],
            $this->languageOptions['dataFolder']
        );
    }

    /**
     * Normalizes bundle key (string) to prevent data loosing while extra lines or spaces or formatting.
     * Method will be applied only to keys, final value will be kept untouched.
     *
     * @param string $string String to be localized.
     * @return string
     */
    public function normalize($string)
    {
        return preg_replace('/[ \t\n]+/', ' ', trim(StringHelper::normalizeEndings(trim($string))));
    }

    /**
     * Translate and format string fetched from bundle, new strings will be automatically registered
     * in bundle with key identical to string itself. Function support embedded formatting, to enable
     * it provide arguments to insert after string. This method is indexable and will be automatically
     * collected to bundles.
     *
     * Examples:
     * I18n::get('bundle', 'Some Message');
     * I18n::get('bundle', 'Hello %s', $name);
     *
     * @param string $bundle Bundle name.
     * @param string $string String to be localized, should be sprintf compatible if formatting
     *                       required.
     * @return string
     */
    public function get($bundle, $string)
    {
        $this->loadBundle($bundle);

        if (!isset($this->bundles[$bundle][$string = $this->normalize($string)]))
        {
            $this->bundles[$bundle][$string] = func_get_arg(1);
            $this->saveBundle($bundle);
        }

        if (func_num_args() == 2)
        {
            //Just simple text line
            return $this->bundles[$bundle][$string];
        }

        if (is_array(func_get_arg(2)))
        {
            return interpolate($this->bundles[$bundle][$string], func_get_arg(2));
        }

        $arguments = array_slice(func_get_args(), 1);
        $arguments[0] = $this->bundles[$bundle][$string];

        //Formatting
        return call_user_func_array('sprintf', $arguments);
    }

    /**
     * Force translation for specified string in bundle file. Will replace existed translation or
     * create new one.
     *
     * @param string $bundle      Bundle name.
     * @param string $string      String to be localized, should be sprintf compatible if formatting
     *                            required.
     * @param string $translation String translation, by default equals to string itself.
     * @return string
     */
    public function set($bundle, $string, $translation = '')
    {
        $this->loadBundle($bundle);
        $this->bundles[$bundle][$string] = func_num_args() == 2 ? $translation : $string;
        $this->saveBundle($bundle);
    }

    /**
     * Format phase according to formula defined in selected language. Phase should include "%s" which
     * will be replaced with number provided as second argument. This method is indexable and will
     * be automatically collected to bundles.
     *
     * Examples:
     * I18n::pluralize("%s user", $users);
     *
     * All pluralization phases stored in same bundle defined in i18n config.
     *
     * @param string $phrase       Pluralization phase.
     * @param int    $number       Number has to be used in pluralization phrase.
     * @param bool   $numberFormat True to format number using number_format.
     * @return string
     */
    public function pluralize($phrase, $number, $numberFormat = true)
    {
        $this->loadBundle($bundle = $this->config['plurals']);

        if (!isset($this->bundles[$bundle][$phrase = $this->normalize($phrase)]))
        {
            $this->bundles[$bundle][$phrase] = array_pad(
                array(),
                $this->getPluralizer()->countForms(),
                func_get_arg(0)
            );

            $this->saveBundle($bundle);
        }

        if (is_null($number))
        {
            return $this->bundles[$bundle][$phrase];
        }

        return sprintf(
            $this->getPluralizer()->getForm($number, $this->bundles[$bundle][$phrase]),
            $numberFormat ? number_format($number) : $number
        );
    }
}