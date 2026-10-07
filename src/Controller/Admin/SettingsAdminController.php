<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AuditAction;
use App\Form\Admin\SettingsType;
use App\Repository\MediaFileRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Settings\SettingDefinition;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SettingsAdminController extends AppController
{
    #[Route('/{group}', name: 'admin_settings', defaults: ['group' => 'general'], methods: ['GET', 'POST'])]
    public function edit(
        string $group,
        Request $request,
        SettingsRegistry $registry,
        SettingsService $settings,
        MediaFileRepository $media,
        AuditLogger $audit,
        ContentCacheInvalidator $cache,
    ): Response {
        $groups = $registry->byGroup();
        if (!isset($groups[$group])) {
            throw $this->createNotFoundException();
        }
        $definitions = $groups[$group];

        // Current values in form representation.
        $data = [];
        foreach ($definitions as $def) {
            $value = $settings->get($def->key);
            $data[$def->key] = match ($def->type) {
                SettingDefinition::TYPE_INT_LIST => implode(', ', (array) $value),
                SettingDefinition::TYPE_MEDIA => null !== $value ? $media->find($value) : null,
                default => $value,
            };
        }

        $form = $this->createForm(SettingsType::class, $data, ['definitions' => $definitions]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $values = [];
            foreach ($form->getData() as $key => $value) {
                $values[$key] = $value instanceof \App\Entity\MediaFile ? $value->getId() : $value;
            }
            $changed = $settings->save($values, $this->requireUser());
            if ([] !== $changed) {
                $audit->log(AuditAction::SettingsChanged, null, ['group' => $group, 'keys' => $changed], true);
                $cache->all();
            }
            $this->addFlash('success', [] === $changed ? 'No changes.' : 'Settings saved ('.\count($changed).' changed).');

            return $this->redirectToRoute('admin_settings', ['group' => $group]);
        }

        return $this->render('admin/settings/index.html.twig', [
            'form' => $form,
            'group' => $group,
            'groups' => SettingsRegistry::GROUPS,
        ]);
    }
}
