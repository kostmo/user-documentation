<?hh // strict
/*
 *  Copyright (c) 2004-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

use type HHVM\UserDocumentation\{
  LocalConfig,
  UIGlyphIcon,
  comment,
  github_issue_link,
  search_bar,
};
use type Facebook\Experimental\Http\Message\{
  ResponseInterface,
  ServerRequestInterface,
};
use type Facebook\XHP\HTML\{
  a,
  body,
  div,
  doctype,
  footer,
  h1,
  h2,
  head,
  html,
  i,
  li,
  link,
  meta,
  script,
  span,
  strong,
  title,
  ul,
};

use namespace HH\Lib\C;
use namespace Facebook\XHP\Core as x;
use namespace HHVM\UserDocumentation\{script, static_res, ui};

abstract class NonRoutableWebPageController extends WebController {
  protected abstract function getTitleAsync(): Awaitable<string>;
  protected abstract function getBodyAsync(): Awaitable<x\node>;

  protected function getHeadingAsync(): Awaitable<string> {
    return $this->getTitleAsync();
  }

  protected function getExtraBodyClass(): ?string {
    return null;
  }

  protected function getGithubIssueTitle(): ?string {
    /* Bad automatic title: "Issue with /foo/bar"
     * Good automatic title: "404: /foo/bar"
     *
     * There isn't a general way to create a good title, so leave it blank and
     * hope the user picks something decent. We get the URL in the
     * automatically-appended metadata anyway.
     *
     * "Issue with /foo/bar" is 'bad' because we will want to rename any issues
     * with that title during triage to something more descriptive.
     */

    return null;
  }

  /** Extend this if you want custom logic (e.g. redirects) before anything
   * else happens. */
  protected async function beforeResponseAsync(): Awaitable<void> {}

  protected function getGithubIssueBody(): string {
    return <<<EOF
Please complete the information below:

# Where is the problem?

- e.g. "The section describing how widgets work"

# What is the problem?

- e.g. "It doesn't explain that widgets are singletons"
EOF;
  }

  <<__Override>>
  final public async function getResponseAsync(
    ResponseInterface $response,
  ): Awaitable<ResponseInterface> {
    await $this->beforeResponseAsync();
    concurrent {
      $title = await $this->getTitleAsync();
      $content = await $this->getContentPaneAsync();
    }
    $content->appendChild($this->getFooter());

    $extra_class = $this->getExtraBodyClass();
    $body_class = $this->getBodyClass($extra_class);
    $google_analytics = null;
    $open_search = null;
    $require_secure = false;

    $canonical_url = 'http://docs.hhvm.com'.$this->getRequestedPath();
    switch ($this->getRequestedHost()) {
      case 'beta.docs.hhvm.com':
        throw new RedirectException($canonical_url);
      case 'docs.hhvm.com':
        $google_analytics =
          <script:google_analytics trackingID="UA-49208336-3" />;
        $open_search =
          <link
            rel="search"
            type="application/opensearchdescription+xml"
            href="/search.xml"
          />;
        $require_secure = true;
        break;
      case 'staging.docs.hhvm.com':
        $require_secure = true;
        break;
      default: // probably dev environment
        break;
    }

    if ($require_secure) {
      $this->requireSecureConnection();
    }

    $xhp =
      <doctype>
        <html>
          <head>
            <title>{$title}</title>
            <meta charset="utf-8" />
            <meta
              name="viewport"
              content="width=device-width, initial-scale=1.0"
            />
            <link rel="shortcut icon" href="/favicon.png" />

            <meta property="og:type" content="website" />
            <meta property="og:url" content={$canonical_url} />
            <meta property="og:title" content={$title} />
            <meta property="og:description" content="Offical documentation for Hack and HHVM" />
            <meta property="og:image" content="http://docs.hhvm.com/favicon.png" />

            {$open_search}
            <comment>
              Build ID: {LocalConfig::getBuildID()}
            </comment>
            <static_res:stylesheet path="/css/main.css" media="screen" />
            <link
              href=
                "https://fonts.googleapis.com/css?family=Open+Sans:400,400italic,700,700italic|Roboto:700"
              rel="stylesheet"
            />
            {$google_analytics}
            <link
              href=
                "https://cdnjs.cloudflare.com/ajax/libs/highlight.js/9.12.0/styles/github-gist.min.css"
              rel="stylesheet"
              type="text/css"
            />
            <script
              src=
                "https://cdnjs.cloudflare.com/ajax/libs/highlight.js/9.12.0/highlight.min.js"
            />
          </head>
          <body class={$body_class}>
            {$this->getHeader()}
            {$this->getSideNav()}
            {$content}
            {$this->getEagerFetchScript()}
          </body>
        </html>
      </doctype>;
    $xhp->setContext('ServerRequestInterface', $this->request);
    $html = await $xhp->toStringAsync();

    await $response->getBody()->writeAllAsync($html);
    $response = $response
      ->withStatus($this->getStatusCode())
      ->withHeader(
        'Cache-Control',
        vec['max-age=60'],
      ); // enough for pre-fetching :)

    if ($require_secure) {
      $response = $response->withHeader(
        'Strict-Transport-Security',
        vec['max-age='.(60 * 60 * 24 * 28)], // 4 weeks
      );
    }

    return $response;
  }

  protected function getStatusCode(): int {
    return 200;
  }

  final protected async function getContentPaneAsync(): Awaitable<x\node> {
    concurrent {
      $heading = await $this->getHeadingAsync();
      $body = await $this->getBodyAsync();
    }

    $breadcrumbs = $this->getBreadcrumbs();
    if ($breadcrumbs !== null) {
      invariant(
        !C\is_empty($breadcrumbs),
        'If using breadcrumbs, specify at least one',
      );
      $breadcrumbs = <ui:breadcrumbs stack={$breadcrumbs} />;
    }
    return (
      <div class="mainContainer">
        {$breadcrumbs}
        {$this->getTitleContent($heading)}
        <div class="widthWrapper flexWrapper">
          <div class="mainWrapper">
            {$body}
          </div>
        </div>
      </div>
    );
  }

  private function getBodyClass(?string $extra_class): string {
    $class = 'bodyClass'.
      ucwords($this->getRawParameter_UNSAFE('product') ?? 'Default');
    if ($extra_class !== null) {
      $class = $class.' '.$extra_class;
    }
    if ($this->isFacebookIP()) {
      $class .= ' isFbViewer';
    } else {
      $class .= ' isNotFbViewer';
    }
    return $class;
  }

  private function getTitleContent(string $title): x\node {
    $title_class = "mainTitle mainTitle".
      $this->getRawParameter_UNSAFE('product');
    return
      <div class={$title_class}>
        <div class="widthWrapper">
          <h1 class="pageTitle">{$title}</h1>
        </div>
      </div>;
  }

  protected function getSideNav(): x\node {
    return <x:frag />;
  }

  protected function getBreadcrumbs(): ?vec<(string, ?string)> {
    return null;
  }

  protected function getHeader(): x\node {
    $header_class = "header headerType".
      $this->getRawParameter_UNSAFE('product');
    return
      <div class={$header_class}>
        <div class="widthWrapper">
          <div class="headerLogo hackLogo">
            <a href="/hack/">
              <span class="logoCSS hackLogoCSS">
                <i class="logoPolygon a a1" />
                <i class="logoPolygon b b1" />
                <i class="logoPolygon b b2" />
                <i class="logoPolygon a a3" />
                <i class="logoPolygon b b3" />
                <i class="logoPolygon a a4" />
                <i class="logoPolygon b b4" />
              </span>
              <strong>Hack</strong>
            </a>
          </div>
          <div class="headerLogo hhvmLogo">
            <a href="/hhvm/">
              <span class="logoCSS hhvmLogoCSS">
                <i class="logoPolygon a a1" />
                <i class="logoPolygon b b1" />
                <i class="logoPolygon a a2" />
                <i class="logoPolygon b b2" />
                <i class="logoPolygon a a3" />
                <i class="logoPolygon b b3" />
                <i class="logoPolygon a a4" />
                <i class="logoPolygon b b4" />
              </span>
              <strong>HHVM</strong>
            </a>
          </div>
          <div class="headerElement githubIssueLink">
            <github_issue_link
              issueTitle={$this->getGithubIssueTitle()}
              issueBody={$this->getGithubIssueBody()}
              controller={static::class}>
              <ui:glyph icon={UIGlyphIcon::BUG} />
              report a problem or make a suggestion
            </github_issue_link>
          </div>
          <search_bar
            class="headerElement"
            placeholder="Search our Documentation"
          />
        </div>
      </div>;
  }

  /* If you're reading this, you probably want to remove 'final' so that you
   * can pull stuff out of $request or $parameters. Instead:
   *
   * - use getRequiredStringParam() and friends to get the data you need in a
   *   safe and abstracted way
   * - if there isn't an existing abstraction that fits your needs, add one
   */
  final public function __construct(
    ImmMap<string, string> $parameters,
    private ServerRequestInterface $request,
  ) {
    parent::__construct($parameters, $request);
  }

  private function getFeedbackFooter(): x\node {
    return
      <div class="footerPanel footerPanelFullWidth">
        <h2>See something wrong?</h2>
        <ui:button className="gitHubIssueButton" glyph={UIGlyphIcon::BUG}>
          <span>
            <github_issue_link
              issueTitle={$this->getGithubIssueTitle()}
              issueBody={$this->getGithubIssueBody()}
              controller={static::class}>
              Report a problem or make a suggestion.
            </github_issue_link>
          </span>
        </ui:button>
      </div>;
  }

  private function getFooter(): footer {
    return
      <footer class="footerWrapper">
        <div class="mainWrapper">
          {$this->getFeedbackFooter()}
          <div class="footerPanel">
            <h2>Hack</h2>
            <ul>
              <li><a href="/hack/overview/">Overview</a></li>
              <li><a href="/hack/getting-started/">Getting Started</a></li>
              <li><a href="/hack/tools/">Tools</a></li>
              <li><a href="/hack/reference/">API Reference</a></li>
            </ul>
          </div>
          <div class="footerPanel">
            <h2>HHVM</h2>
            <ul>
              <li><a href="/hhvm/getting-started/">Getting Started</a></li>
              <li><a href="/hhvm/installation/">Installation</a></li>
              <li><a href="/hhvm/basic-usage/">Basic Usage</a></li>
              <li><a href="/hhvm/configuration/">Configuration</a></li>
            </ul>
          </div>
          <div class="footerPanel">
            <h2>Community</h2>
            <ul>
              <li><a href="https://www.facebook.com/groups/hhvm.general">Facebook Group</a></li>
              <li><a href="https://twitter.com/hacklang">Hack Twitter</a></li>
              <li><a href="https://twitter.com/HipHopVM">HHVM Twitter</a></li>
              <li><a href="https://hhvm.com/slack">Slack</a></li>
            </ul>
          </div>
          <div class="footerPanel">
            <h2>Resources</h2>
            <ul>
              <li><a href="http://hhvm.com/blog">Blog</a></li>
              <li><a href="http://hacklang.org">Hack Website</a></li>
              <li><a href="http://hhvm.com">HHVM Website</a></li>
              <li><a href="https://www.facebook.com/hhvm">Facebook Page</a></li>
            </ul>
          </div>
          <div class="footerPanel">
            <h2>GitHub</h2>
            <ul>
              <li><a href="https://github.com/facebook/hhvm">Hack and HHVM</a></li>
              <li><a href="https://github.com/hhvm/user-documentation">Source for This Site</a></li>
            </ul>
          </div>
        </div>
      </footer>;
  }

  private function getEagerFetchScript(): script {
    $code = <<<EOF
// Prefetch pages on this site for performance
if (document.querySelectorAll) {
  var links = document.querySelectorAll(
    '.mainContainer a, .navOuterContainer a'
  );

  for (var i = 0; i < links.length; i++) {
    var link = links[i];
    if (link.pathname == window.location.pathname) {
      continue;
    }
    if (link.hostname !== window.location.hostname) {
      continue;
    }

    link.addEventListener(
      'mouseover',
      function() {
        var req = new XMLHttpRequest();
        req.open('GET', this.href, /* async = */ true);
        req.send();
      }.bind(link)
    );
  }
}
EOF;
    return <script language="javascript">{$code}</script>;
  }
}
