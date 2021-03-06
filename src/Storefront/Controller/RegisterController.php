<?php declare(strict_types=1);

namespace Shopware\Storefront\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Exception\CustomerAlreadyConfirmedException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundByHashException;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountRegistrationService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\QueryDataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\AffiliateTracking\AffiliateTrackingListener;
use Shopware\Storefront\Framework\Captcha\Annotation\Captcha;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Shopware\Storefront\Page\Account\CustomerGroupRegistration\AbstractCustomerGroupRegistrationPageLoader;
use Shopware\Storefront\Page\Account\Login\AccountLoginPageLoader;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @RouteScope(scopes={"storefront"})
 */
class RegisterController extends StorefrontController
{
    /**
     * @var AccountLoginPageLoader
     */
    private $loginPageLoader;

    /**
     * @var AccountRegistrationService
     */
    private $accountRegistrationService;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var CheckoutRegisterPageLoader
     */
    private $registerPageLoader;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var AbstractCustomerGroupRegistrationPageLoader
     */
    private $customerGroupRegistrationPageLoader;

    public function __construct(
        AccountLoginPageLoader $loginPageLoader,
        AccountRegistrationService $accountRegistrationService,
        CartService $cartService,
        CheckoutRegisterPageLoader $registerPageLoader,
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $customerRepository,
        AbstractCustomerGroupRegistrationPageLoader $customerGroupRegistrationPageLoader
    ) {
        $this->loginPageLoader = $loginPageLoader;
        $this->accountRegistrationService = $accountRegistrationService;
        $this->cartService = $cartService;
        $this->registerPageLoader = $registerPageLoader;
        $this->systemConfigService = $systemConfigService;
        $this->customerRepository = $customerRepository;
        $this->customerGroupRegistrationPageLoader = $customerGroupRegistrationPageLoader;
    }

    /**
     * @Route("/account/register", name="frontend.account.register.page", methods={"GET"})
     */
    public function accountRegisterPage(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        if ($context->getCustomer() && $context->getCustomer()->getGuest()) {
            return $this->redirectToRoute('frontend.account.logout.page');
        }

        if ($context->getCustomer()) {
            return $this->redirectToRoute('frontend.account.home.page');
        }

        $redirect = $request->query->get('redirectTo', 'frontend.account.home.page');

        $page = $this->loginPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/storefront/page/account/register/index.html.twig', [
            'redirectTo' => $redirect,
            'redirectParameters' => $request->get('redirectParameters', json_encode([])),
            'page' => $page,
            'data' => $data,
        ]);
    }

    /**
     * @Route("/customer-group-registration/{customerGroupId}", name="frontend.account.customer-group-registration.page", methods={"GET"})
     */
    public function customerGroupRegistration(string $customerGroupId, Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        if ($context->getCustomer() && $context->getCustomer()->getGuest()) {
            return $this->redirectToRoute('frontend.account.logout.page');
        }

        if ($context->getCustomer()) {
            return $this->redirectToRoute('frontend.account.home.page');
        }

        $redirect = $request->query->get('redirectTo', 'frontend.account.home.page');

        $page = $this->customerGroupRegistrationPageLoader->load($request, $context);

        if ($page->getGroup()->getTranslation('registrationOnlyCompanyRegistration')) {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_BUSINESS);
        }

        return $this->renderStorefront('@Storefront/storefront/page/account/customer-group-register/index.html.twig', [
            'redirectTo' => $redirect,
            'redirectParameters' => $request->get('redirectParameters', json_encode([])),
            'errorParameters' => json_encode(['customerGroupId' => $customerGroupId]),
            'page' => $page,
            'data' => $data,
        ]);
    }

    /**
     * @Route("/checkout/register", name="frontend.checkout.register.page", options={"seo"="false"}, methods={"GET"})
     */
    public function checkoutRegisterPage(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        /** @var string $redirect */
        $redirect = $request->get('redirectTo', 'frontend.checkout.confirm.page');

        if ($context->getCustomer()) {
            return $this->redirectToRoute($redirect);
        }

        if ($this->cartService->getCart($context->getToken(), $context)->getLineItems()->count() === 0) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $page = $this->registerPageLoader->load($request, $context);

        return $this->renderStorefront(
            '@Storefront/storefront/page/checkout/address/index.html.twig',
            ['redirectTo' => $redirect, 'page' => $page, 'data' => $data]
        );
    }

    /**
     * @Route("/account/register", name="frontend.account.register.save", methods={"POST"})
     * @Captcha
     */
    public function register(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        if ($context->getCustomer()) {
            return $this->redirectToRoute('frontend.account.home.page');
        }

        try {
            if (!$data->has('differentShippingAddress')) {
                $data->remove('shippingAddress');
            }

            $data->set('storefrontUrl', $request->attributes->get(RequestTransformer::STOREFRONT_URL));

            $data = $this->prepareAffiliateTracking($data, $request->getSession());
            $this->accountRegistrationService->register($data, $data->has('guest'), $context, $this->getAdditionalRegisterValidationDefinitions($data, $context));
        } catch (ConstraintViolationException $formViolations) {
            if (!$request->request->has('errorRoute')) {
                throw new MissingRequestParameterException('errorRoute');
            }

            $params = $this->decodeParam($request, 'errorParameters');

            // this is to show the correct form because we have different usecases (account/register||checkout/register)
            return $this->forwardToRoute($request->get('errorRoute'), ['formViolations' => $formViolations], $params);
        }

        if ($this->isDoubleOptIn($data, $context)) {
            return $this->redirectToRoute('frontend.account.register.page');
        }

        return $this->createActionResponse($request);
    }

    /**
     * @Route("/registration/confirm", name="frontend.account.register.mail", methods={"GET"})
     */
    public function confirmRegistration(SalesChannelContext $context, QueryDataBag $queryDataBag): Response
    {
        try {
            $customerId = $this->accountRegistrationService->finishDoubleOptInRegistration($queryDataBag, $context);
        } catch (CustomerNotFoundByHashException | CustomerAlreadyConfirmedException | ConstraintViolationException $exception) {
            $this->addFlash('danger', $this->trans('account.confirmationIsAlreadyDone'));

            return $this->redirectToRoute('frontend.account.register.page');
        }

        /** @var CustomerEntity $customer */
        $customer = $this->customerRepository->search(new Criteria([$customerId]), $context->getContext())->first();

        if ($customer->getGuest()) {
            $this->addFlash('success', $this->trans('account.doubleOptInMailConfirmationSuccessfully'));

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $this->addFlash('success', $this->trans('account.doubleOptInRegistrationSuccessfully'));

        return $this->redirectToRoute('frontend.account.home.page');
    }

    private function isDoubleOptIn(DataBag $data, SalesChannelContext $context): bool
    {
        $configKey = $data->has('guest')
            ? 'core.loginRegistration.doubleOptInGuestOrder'
            : 'core.loginRegistration.doubleOptInRegistration';

        $doubleOptInRequired = $this->systemConfigService
            ->get($configKey, $context->getSalesChannel()->getId());

        if (!$doubleOptInRequired) {
            return false;
        }

        if ($data->has('guest')) {
            $this->addFlash('success', $this->trans('account.optInGuestAlert'));

            return true;
        }

        $this->addFlash('success', $this->trans('account.optInRegistrationAlert'));

        return true;
    }

    private function getAdditionalRegisterValidationDefinitions(DataBag $data, SalesChannelContext $context): DataValidationDefinition
    {
        $definition = new DataValidationDefinition('storefront.confirmation');

        if ($this->systemConfigService->get('core.loginRegistration.requireEmailConfirmation', $context->getSalesChannel()->getId())) {
            $definition->add('emailConfirmation', new NotBlank(), new EqualTo([
                'value' => $data->get('email'),
            ]));
        }

        if ($data->has('guest')) {
            return $definition;
        }

        if ($this->systemConfigService->get('core.loginRegistration.requirePasswordConfirmation', $context->getSalesChannel()->getId())) {
            $definition->add('passwordConfirmation', new NotBlank(), new EqualTo([
                'value' => $data->get('password'),
            ]));
        }

        return $definition;
    }

    private function prepareAffiliateTracking(RequestDataBag $data, SessionInterface $session): DataBag
    {
        $affiliateCode = $session->get(AffiliateTrackingListener::AFFILIATE_CODE_KEY);
        $campaignCode = $session->get(AffiliateTrackingListener::CAMPAIGN_CODE_KEY);
        if ($affiliateCode !== null && $campaignCode !== null) {
            $data->add([
                AffiliateTrackingListener::AFFILIATE_CODE_KEY => $affiliateCode,
                AffiliateTrackingListener::CAMPAIGN_CODE_KEY => $campaignCode,
            ]);
        }

        return $data;
    }
}
