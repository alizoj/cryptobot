<?php

declare(strict_types=1);

namespace common\components;

use yii\base\BootstrapInterface;
use yii\web\Application as WebApplication;

/**
 * Sets the application language from the visitor's `lang` cookie (en | ru),
 * defaulting to English. Registered in the app bootstrap so it runs before
 * controllers and views — making Yii::$app->language drive the <html lang>
 * attribute and language-aware views (notably the How-it-works page).
 *
 * The cookie is written by {@see \backend\controllers\SiteController::actionLanguage()};
 * this class only reads it. Console apps have no request, so it no-ops there.
 */
final class LanguageSelector implements BootstrapInterface
{
    /** @var list<string> */
    public const SUPPORTED = ['en', 'ru'];
    public const DEFAULT_LANGUAGE = 'en';
    public const COOKIE = 'lang';

    public function bootstrap($app): void
    {
        if (!$app instanceof WebApplication) {
            return;
        }
        $lang = (string)$app->request->cookies->getValue(self::COOKIE, self::DEFAULT_LANGUAGE);
        $app->language = in_array($lang, self::SUPPORTED, true) ? $lang : self::DEFAULT_LANGUAGE;
    }
}
