import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Form from 'flarum/common/components/Form';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

type AdRecord = {
  id: number;
  title: string;
  imageUrl: string;
  targetUrl: string;
  slotLabel: string;
  slotPosition?: number | null;
  durationLabel: string;
  totalPrice: number;
  status: string;
  endsAt?: string | null;
  reviewNote?: string | null;
  user?: { displayName?: string; username?: string };
};

type Config = {
  enabled: boolean;
  sidebarEnabled: boolean;
  topEnabled: boolean;
  sidebarSlots: number;
  topSlots: number;
  sidebarPricePerMonth: number;
  topPricePerMonth: number;
  maxImageSizeKb: number;
  currencyName: string;
  currencyIcon: string;
};

const DEFAULT_CONFIG: Config = {
  enabled: true,
  sidebarEnabled: true,
  topEnabled: true,
  sidebarSlots: 10,
  topSlots: 3,
  sidebarPricePerMonth: 20,
  topPricePerMonth: 30,
  maxImageSizeKb: 2048,
  currencyName: '积分',
  currencyIcon: 'fas fa-coins',
};

class AdvertisingSettingsPage extends ExtensionPage {
  private ads: AdRecord[] = [];
  private loadingAds = true;
  private savingAd: number | null = null;
  private savingConfig = false;
  private configLoaded = false;
  private refreshTimer: number | null = null;
  private config: Config = { ...DEFAULT_CONFIG };
  private activeSection = 'pending';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.loadConfig();
    this.loadAds();
    this.refreshTimer = window.setInterval(() => this.loadAds(), 30000);
  }

  onremove() {
    if (this.refreshTimer !== null) window.clearInterval(this.refreshTimer);
    this.refreshTimer = null;
  }

  apiUrl(path: string) {
    return `${app.forum.attribute('apiUrl')}${path}`;
  }

  async loadConfig() {
    try {
      const response: any = await app.request({ method: 'GET', url: this.apiUrl('/advertising/admin/config') });
      this.config = { ...this.config, ...(response.data || {}) };
      this.configLoaded = true;
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      m.redraw();
    }
  }

  async loadAds() {
    this.loadingAds = true;

    try {
      const response: any = await app.request({ method: 'GET', url: this.apiUrl('/advertising/admin/ads') });
      this.ads = Array.isArray(response.data) ? response.data : [];
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.loadingAds = false;
      m.redraw();
    }
  }

  statusAds(status: string) {
    return this.ads.filter((ad) => ad.status === status);
  }

  expiringAds() {
    const soon = Date.now() + 7 * 24 * 60 * 60 * 1000;

    return this.ads.filter((ad) => ad.status === 'approved' && ad.endsAt && new Date(ad.endsAt).getTime() <= soon);
  }

  currentAds() {
    return this.activeSection === 'expiring' ? this.expiringAds() : this.statusAds(this.activeSection);
  }

  content() {
    const ads = this.currentAds();

    return (
      <div className="container">
        <div className="LowseekaiAdvertisingAdmin">
          <h2>{app.translator.trans('lowseekai-advertising.admin.title')}</h2>
          <p className="helpText">{app.translator.trans('lowseekai-advertising.admin.help')}</p>
          {this.configLoaded ? this.configForm() : <LoadingIndicator />}
          <div className="LowseekaiAdvertisingAdmin-review">
            <div className="LowseekaiAdvertisingAdmin-tabs">
              {this.sectionButton('pending', 'pending_title')}
              {this.sectionButton('approved', 'approved_title')}
              {this.sectionButton('expiring', 'expiring_title')}
              {this.sectionButton('rejected', 'rejected_title')}
              {this.sectionButton('expired', 'expired_title')}
              {this.sectionButton('hidden', 'hidden_title')}
            </div>
            {this.loadingAds ? (
              <LoadingIndicator />
            ) : ads.length ? (
              ads.map((ad) => this.adRow(ad))
            ) : (
              <p className="helpText">{app.translator.trans('lowseekai-advertising.admin.empty')}</p>
            )}
          </div>
        </div>
      </div>
    );
  }

  sectionButton(section: string, translationKey: string) {
    const count = section === 'expiring' ? this.expiringAds().length : this.statusAds(section).length;

    return (
      <Button
        className={`Button ${this.activeSection === section ? 'Button--primary' : 'Button--secondary'}`}
        onclick={() => (this.activeSection = section)}
      >
        {app.translator.trans(`lowseekai-advertising.admin.${translationKey}`)} ({count})
      </Button>
    );
  }

  configForm() {
    return (
      <Form>
        <div className="Form-group">
          <Switch state={this.config.enabled} onchange={(value: boolean) => (this.config.enabled = value)}>
            {app.translator.trans('lowseekai-advertising.admin.enabled')}
          </Switch>
        </div>
        <div className="Form-group">
          <Switch state={this.config.sidebarEnabled} onchange={(value: boolean) => (this.config.sidebarEnabled = value)}>
            {app.translator.trans('lowseekai-advertising.admin.sidebar_enabled')}
          </Switch>
        </div>
        <div className="Form-group">
          <Switch state={this.config.topEnabled} onchange={(value: boolean) => (this.config.topEnabled = value)}>
            {app.translator.trans('lowseekai-advertising.admin.top_enabled')}
          </Switch>
        </div>
        {this.numberField('sidebarSlots', 'lowseekai-advertising.admin.sidebar_slots', 1, 50)}
        {this.numberField('topSlots', 'lowseekai-advertising.admin.top_slots', 1, 12)}
        {this.numberField('sidebarPricePerMonth', 'lowseekai-advertising.admin.sidebar_price', 0)}
        {this.numberField('topPricePerMonth', 'lowseekai-advertising.admin.top_price', 0)}
        {this.numberField('maxImageSizeKb', 'lowseekai-advertising.admin.max_image_size', 128)}
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.admin.currency_name')}</label>
          <input className="FormControl" value={this.config.currencyName} oninput={(event: any) => (this.config.currencyName = event.target.value)} />
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.admin.currency_icon')}</label>
          <input className="FormControl" value={this.config.currencyIcon} oninput={(event: any) => (this.config.currencyIcon = event.target.value)} />
        </div>
        <div className="Form-group Form-controls">
          <Button
            type="button"
            className="Button Button--primary"
            icon="fas fa-save"
            loading={this.savingConfig}
            disabled={this.savingConfig}
            onclick={() => this.saveConfig()}
          >
            {app.translator.trans('lowseekai-advertising.admin.save')}
          </Button>
        </div>
      </Form>
    );
  }

  numberField(
    key: keyof Pick<Config, 'sidebarSlots' | 'topSlots' | 'sidebarPricePerMonth' | 'topPricePerMonth' | 'maxImageSizeKb'>,
    labelKey: string,
    min: number,
    max?: number
  ) {
    return (
      <div className="Form-group">
        <label>{app.translator.trans(labelKey)}</label>
        <input
          type="number"
          min={min}
          max={max}
          className="FormControl"
          value={this.config[key]}
          oninput={(event: any) => (this.config[key] = Number(event.target.value))}
        />
      </div>
    );
  }

  adRow(ad: AdRecord) {
    const busy = this.savingAd === ad.id;
    const canApprove = ad.status === 'pending';
    const canHide = ad.status === 'approved';
    const canRestore = ad.status === 'hidden';

    return (
      <div className="LowseekaiAdvertisingAdmin-ad" key={ad.id}>
        <img src={ad.imageUrl} alt={ad.title} />
        <div className="LowseekaiAdvertisingAdmin-adBody">
          <strong>{ad.title}</strong>
          <span>
            {ad.user?.displayName || ad.user?.username || '-'} · {ad.slotLabel} ·{' '}
            {app.translator.trans('lowseekai-advertising.admin.position_option', { position: ad.slotPosition || '-' })} · {ad.durationLabel} ·{' '}
            {ad.totalPrice} {this.config.currencyName}
          </span>
          {ad.endsAt ? (
            <span>
              {app.translator.trans('lowseekai-advertising.admin.ends_at', {
                date: new Date(ad.endsAt).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' }),
              })}
            </span>
          ) : null}
          <a href={ad.targetUrl} target="_blank" rel="noopener noreferrer">
            {ad.targetUrl}
          </a>
          {ad.status === 'pending' || ad.status === 'rejected' ? (
            <textarea
              className="FormControl"
              placeholder={app.translator.trans('lowseekai-advertising.admin.review_note')}
              value={ad.reviewNote || ''}
              oninput={(event: any) => (ad.reviewNote = event.target.value)}
            />
          ) : null}
          <div className="LowseekaiAdvertisingAdmin-actions">
            {canApprove ? (
              <Button
                className="Button Button--primary"
                icon="fas fa-check"
                loading={busy}
                disabled={busy}
                onclick={() => this.updateAd(ad, 'approved')}
              >
                {app.translator.trans('lowseekai-advertising.admin.approve')}
              </Button>
            ) : null}
            {canApprove ? (
              <Button
                className="Button Button--danger"
                icon="fas fa-times"
                loading={busy}
                disabled={busy}
                onclick={() => this.updateAd(ad, 'rejected')}
              >
                {app.translator.trans('lowseekai-advertising.admin.reject')}
              </Button>
            ) : null}
            {canHide ? (
              <Button
                className="Button Button--danger"
                icon="fas fa-eye-slash"
                loading={busy}
                disabled={busy}
                onclick={() => this.updateAd(ad, 'hidden')}
              >
                {app.translator.trans('lowseekai-advertising.admin.hide')}
              </Button>
            ) : null}
            {canRestore ? (
              <Button
                className="Button Button--primary"
                icon="fas fa-eye"
                loading={busy}
                disabled={busy}
                onclick={() => this.updateAd(ad, 'approved')}
              >
                {app.translator.trans('lowseekai-advertising.admin.restore')}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  async saveConfig() {
    this.savingConfig = true;

    try {
      const response: any = await app.request({
        method: 'POST',
        url: this.apiUrl('/advertising/admin/config'),
        body: { data: { attributes: this.config } },
      });
      this.config = { ...this.config, ...(response.data || {}) };
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.admin.saved'));
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.savingConfig = false;
      m.redraw();
    }
  }

  async updateAd(ad: AdRecord, status: string) {
    this.savingAd = ad.id;

    try {
      await app.request({
        method: 'PATCH',
        url: this.apiUrl(`/advertising/admin/ads/${ad.id}`),
        body: { data: { attributes: { status, reviewNote: ad.reviewNote || '' } } },
      });
      await this.loadAds();
      app.alerts.show(
        { type: 'success' },
        app.translator.trans(`lowseekai-advertising.admin.${status === 'approved' ? 'approved' : status === 'hidden' ? 'hidden' : 'rejected'}`)
      );
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.savingAd = null;
      m.redraw();
    }
  }

  errorMessage(error: any) {
    const responseError = error?.response?.errors?.[0];

    return responseError?.detail || responseError?.title || error?.message || app.translator.trans('lowseekai-advertising.error');
  }
}

app.initializers.add('lowseekai/advertising/admin', () => {
  app.registry
    .for('lowseekai-advertising')
    .registerPage(AdvertisingSettingsPage)
    .registerPermission(
      {
        icon: 'fas fa-bullhorn',
        label: app.translator.trans('lowseekai-advertising.admin.permissions.submit'),
        permission: 'lowseekai-advertising.submit',
        allowGuest: false,
      },
      'view'
    )
    .registerPermission(
      {
        icon: 'fas fa-gavel',
        label: app.translator.trans('lowseekai-advertising.admin.permissions.manage'),
        permission: 'lowseekai-advertising.manage',
        allowGuest: false,
      },
      'moderate'
    );
});
