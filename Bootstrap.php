<?php

use Shopware\Models\Config\Form;

class Shopware_Plugins_Frontend_LiveStockCheckForBasket_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
    /**
     * @return string
     */
    public function getVersion()
    {
        return '1.0.0';
    }

    /**
     * @return array
     */
    public function getInfo()
    {
        return [
            'version' => $this->getVersion(),
            'autor' => 'Kevin Mauel <kevin.mauel2@gmail.com>',
            'label' => $this->getLabel(),
            'source' => 'Community',
            'description' => "Calls a configurable url to retrieve actual quantity of the product,\n\r".
                             "whenever an user adds an article to the basket.\n\r".
                             "Given variables are {productId}, e.g. SW10002 from s_articles_details.ordernumber.",
            'license' => 'MIT',
            'copyright' => 'Copyright © ' . date('Y') . ', Kevin Mauel'
        ];
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return 'Live stock checking';
    }

    /**
     * Register the autoloader
     */
    public function afterInit()
    {
        $this->get('loader')->registerNamespace('Shopware\CallAfterCustomerOrder', __DIR__ . DIRECTORY_SEPARATOR);
    }

    /**
     * Installs the plugin
     *
     * @return bool
     */
    public function install()
    {
        $this->createForm($this->Form());

        // Whenever an order confirmation mail would be sent
        $this->subscribeEvent(
            'Shopware_Modules_Basket_AddArticle_Start',
            'onAjaxAddArticle'
        );

        $this->subscribeEvent(
            'Shopware_Controllers_Frontend_Checkout::ajaxAddArticleCartAction::before',
            'beforeAjaxAddArticle'
        );

        $this->subscribeEvent(
            'Shopware_Controllers_Frontend_Checkout::ajaxAddArticleCartAction::after',
            'afterAjaxAddArticle'
        );

        return [
            'success' => true
        ];
    }

    public function createForm(Form $form)
    {
        $form->setElement('text', 'url', [
            'label' => 'Live Stock URL',
            'required' => true,
        ]);
    }

    public function enable()
    {
        return [
            'success' => true,
        ];
    }

    public function disable()
    {
        return [
            'success' => true
        ];
    }

    public function getCapabilities()
    {
        return [
            'install' => true,
            'update' => true,
            'enable' => true,
            'secureUninstall' => true
        ];
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        $this->secureUninstall();

        return [
            'success' => true
        ];
    }

    /**
     * @return bool
     */
    public function secureUninstall()
    {
        return true;
    }

    /**
     * Calls a configurable url whenever an user finishes checkout and creates an order
     * @param Enlight_Event_EventArgs $args
     * @return boolean
     */
    public function onAjaxAddArticle(Enlight_Event_EventArgs $args)
    {
        $productId = $args->get('id');
        $quantity = (int) $args->get('quantity');

        try {
            $client = Shopware()->Container()->get('http_client');

            $url = str_replace('{productId}', $productId, trim($this->Config()->get('url')));
            $response = $client->get($url);

            // Add already added quantity from basket
            $quantity += $this->retrieveProductQuantityFromBasket($productId, Shopware()->Session()->get('sessionId'));

            $isAvailable = ((int) $quantity) <= ((int)trim($response->getBody()));

            return $isAvailable ? null : true;
        } catch (\Exception $exception) {
            Shopware()->Container()->get('pluginlogger')->addCritical($exception->getPrevious()->getRequest()->getUrl());
            Shopware()->Container()->get('pluginlogger')->addCritical($exception->getPrevious()->getRequest()->getBody());
            Shopware()->Container()->get('pluginlogger')->addCritical('Error with: ' . $productId);
        }
    }

    /**
     * Retrieve the right basket message and save it as liveBasketInfoMessage
     * @param Enlight_Hook_HookArgs $args
     * @throws Exception
     */
    public function beforeAjaxAddArticle(Enlight_Hook_HookArgs $args)
    {
        /**
         * @var Shopware_Controllers_Frontend_Checkout $checkoutController
         */
        $checkoutController = $args->getSubject();
        $view = $checkoutController->View();

        $productId = $checkoutController->Request()->getParam('sAdd');
        $quantity = $checkoutController->Request()->getParam('sQuantity');

        try {
            $client = Shopware()->Container()->get('http_client');

            $url = str_replace('{productId}', $productId, trim($this->Config()->get('url')));
            $response = $client->get($url);

            $liveStock = (int)trim($response->getBody());

            // Add already added quantity from basket
            $quantity += $this->retrieveProductQuantityFromBasket($productId, Shopware()->Session()->get('sessionId'));

            $view->assign(
                'liveBasketInfoMessage',
                $this->getInstockInfo($productId, $quantity, $liveStock)
            );
        } catch (\Exception $exception) {
            Shopware()->Container()->get('pluginlogger')->addCritical($exception->getPrevious()->getRequest()->getUrl());
            Shopware()->Container()->get('pluginlogger')->addCritical($exception->getPrevious()->getRequest()->getBody());
            Shopware()->Container()->get('pluginlogger')->addCritical('Error with: ' . $productId);
        }
    }

    /**
     * Add basket info message for live stock
     * @param Enlight_Hook_HookArgs $args
     */
    public function afterAjaxAddArticle(Enlight_Hook_HookArgs $args)
    {
        /**
         * @var Shopware_Controllers_Frontend_Checkout $checkoutController
         */
        $checkoutController = $args->getSubject();
        $view = $checkoutController->View();

        if ($view->getAssign('liveBasketInfoMessage')) {
            $view->assign('basketInfoMessage', $view->getAssign('liveBasketInfoMessage'));
        }
    }

    private function retrieveProductQuantityFromBasket($productId, $sessionId)
    {
        $sql = "
            SELECT s_articles.id AS articleID, s_articles.main_detail_id, name AS articleName, taxID,
              additionaltext, s_articles_details.shippingfree, laststock, instock,
              s_articles_details.id as articledetailsID, ordernumber,
              s_articles.configurator_set_id
            FROM s_articles, s_articles_details
            WHERE s_articles_details.ordernumber = ?
            AND s_articles_details.articleID = s_articles.id
            AND s_articles.active = 1
            AND (
                SELECT articleID
                FROM s_articles_avoid_customergroups
                WHERE articleID = s_articles.id
            ) IS NULL
        ";

        $article = Shopware()->Models()->getConnection()->executeQuery($sql, [$productId])->fetch(PDO::FETCH_ASSOC);

        $builder = Shopware()->Models()->getConnection()->createQueryBuilder();

        $builder->select('id', 'quantity')
            ->from('s_order_basket', 'basket')
            ->where('articleID = :articleId')
            ->andWhere('sessionID = :sessionId')
            ->andWhere('ordernumber = :ordernumber')
            ->andWhere('modus != 1')
            ->setParameter('articleId', $article["articleID"])
            ->setParameter('sessionId', $sessionId)
            ->setParameter('ordernumber', $productId);

        /**@var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $builder->execute();

        $result = $statement->fetch();

        return isset($result['quantity']) ? (int) $result['quantity'] : 0;
    }

    private function getInstockInfo($orderNumber, $quantity, $liveStock)
    {
        if (empty($orderNumber)) {
            return Shopware()->Snippets()->getNamespace("frontend")->get('CheckoutSelectVariant',
                'Please select an option to place the required product in the cart', true);
        }

        $quantity = max(1, (int)$quantity);
        $inStock['instock'] = $liveStock;
        $inStock['laststock'] = null;
        $inStock['articleID'] = $orderNumber;
        $inStock['quantity'] += $quantity;

        if (empty($inStock['articleID'])) {
            return Shopware()->Snippets()->getNamespace("frontend")->get('CheckoutArticleNotFound',
                'Product could not be found.', true);
        }

        if ($inStock['instock'] <= 0 && !empty($inStock['laststock'])) {
            return Shopware()->Snippets()->getNamespace("frontend")->get('CheckoutArticleNoStock',
                'Unfortunately we can not deliver the desired product in sufficient quantity', true);
        } elseif ($inStock['instock'] < $inStock['quantity']) {
            $result = 'Unfortunately we can not deliver the desired product in sufficient quantity. (#0 of #1 in stock).';
            $result = Shopware()->Snippets()->getNamespace("frontend")->get('CheckoutArticleLessStock', $result,
                true);
            return str_replace(array('#0', '#1'), array($inStock['instock'], $inStock['quantity']), $result);
        }

        return null;
    }
}
