<?php

declare(strict_types=1);

namespace backend\controllers;

use common\components\LanguageSelector;
use common\models\LoginForm;
use common\services\Trading\PositionStore;
use common\services\Trading\TelegramNotifier;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Cookie;
use yii\web\ErrorAction;
use yii\web\Response;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['login', 'error', 'language'],
                        'allow' => true,
                    ],
                    [
                        'actions' => ['logout', 'index'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions(): array
    {
        return [
            'error' => [
                'class' => ErrorAction::class,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex(): string
    {
        $store = new PositionStore();
        return $this->render('index', [
            'openCount'   => count($store->openPositions()),
            'closedCount' => count($store->closedPositions()),
            'signalCount' => count($store->recentSignals(500)),
            'telegramOn'  => (new TelegramNotifier())->isConfigured(),
            'params'      => Yii::$app->params['trading'] ?? [],
        ]);
    }

    /**
     * Login action.
     *
     * @return string|Response
     */
    public function actionLogin(): string|Response
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $this->layout = 'blank';

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }

        $model->password = '';

        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Logout action.
     *
     * @return Response
     */
    public function actionLogout(): Response
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * Persist the UI language choice (en | ru) in a year-long cookie, then
     * return to the page the switch was clicked from. {@see LanguageSelector}
     * reads the cookie on the next request and sets Yii::$app->language.
     */
    public function actionLanguage(string $lang): Response
    {
        if (in_array($lang, LanguageSelector::SUPPORTED, true)) {
            Yii::$app->response->cookies->add(new Cookie([
                'name'     => LanguageSelector::COOKIE,
                'value'    => $lang,
                'expire'   => time() + 365 * 24 * 3600,
                'httpOnly' => false,
                'sameSite' => Cookie::SAME_SITE_LAX,
            ]));
        }
        $ref = Yii::$app->request->referrer;
        return $this->redirect($ref ?: Yii::$app->homeUrl);
    }
}
