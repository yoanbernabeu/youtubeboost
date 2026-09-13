<?php

declare(strict_types=1);

namespace App\Settings\Controller;

use App\Settings\Locale\AppLocale;
use App\Settings\Locale\LocaleResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Switches the language of the application.
 *
 * The choice is a setting rather than a URL prefix, so changing it is a write
 * followed by a redirect to the page the creator was reading.
 */
final class LocaleController extends AbstractController
{
    #[Route('/locale', name: 'app_locale_switch', methods: ['POST'])]
    public function switch(Request $request, LocaleResolver $locales): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $locales->change(AppLocale::fromCode((string) $request->request->get('locale')));
        $this->addFlash('success', new TranslatableMessage('app.locale.changed'));

        return $this->redirect($this->backTo($request), Response::HTTP_SEE_OTHER);
    }

    /**
     * Returns to the page the form was submitted from.
     *
     * The `submit` token is stateless, so Symfony has already refused anything
     * that did not come from this origin by the time we get here; the check below
     * is what makes that guarantee visible from this file rather than implied by
     * a setting three directories away.
     */
    private function backTo(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $referer;
        }

        return $this->generateUrl('app_catalog');
    }
}
