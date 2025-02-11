<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Routing\ResponseContext;

use Contao\CoreBundle\Routing\ResponseContext\ContaoWebpageResponseContext;
use Contao\PageModel;
use Contao\TestCase\ContaoTestCase;

class ContaoWebpageResponseContextTest extends ContaoTestCase
{
    public function testResponseContext(): void
    {
        /** @var PageModel $pageModel */
        $pageModel = $this->mockClassWithProperties(PageModel::class);
        $pageModel->title = 'My title';
        $pageModel->description = 'My description';
        $pageModel->robots = 'noindex,nofollow';

        $context = new ContaoWebpageResponseContext($pageModel);

        $this->assertSame('My title', $context->getTitle());
        $this->assertSame('My description', $context->getMetaDescription());
        $this->assertSame('noindex,nofollow', $context->getMetaRobots());
    }

    public function testDecodingAndCleanup(): void
    {
        /** @var PageModel $pageModel */
        $pageModel = $this->mockClassWithProperties(PageModel::class);
        $pageModel->title = 'We went from Alpha &#62; Omega ';
        $pageModel->description = 'My description <strong>contains</strong> HTML<br>.';

        $context = new ContaoWebpageResponseContext($pageModel);

        $this->assertSame('We went from Alpha > Omega ', $context->getTitle());
        $this->assertSame('My description contains HTML.', $context->getMetaDescription());
    }
}
