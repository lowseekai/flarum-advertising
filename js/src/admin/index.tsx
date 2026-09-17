import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Form from 'flarum/common/components/Form';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

type DurationPlan = { key: string; label: string; months: number; days: number };
type AdRecord = {
  id: number;
  title: string;
  imageUrl: string;
  targetUrl: string;
  slotLabel: string;
  durationLabel: string;
  totalPrice: number;
  reviewNote?: string | null;
  user?: { displayName?: string; username?: string };
};

class AdvertisingSettingsPage extends ExtensionPage {
  private ads: AdRecord[] = [];
  private loadingAds = true;
  private savingAd: number | null = null;
  private savingConfig = false;
  private configLoaded = false;
  private refreshTimer: number | null = null;
  private config = {
    enabled: true,
    sidebarPricePerMonth: 20,
    topPricePerMonth: 30,
    maxImageSizeKb: 2048,
    currencyName: '积分',
    currencyIcon: 'fas fa-coins',
    durationPlans: [] as DurationPlan[],
  };

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
    if (this.loadingAds && this.ads.length) return;
    this.loadingAds = true;
    try {
      const response: any = await app.request({ method: 'GET', url: this.apiUrl('/advertising/admin/ads?status=pending') });
      this.ads = Array.isArray(response.data) ? response.data : [];
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.loadingAds = false;
      m.redraw();
    }
  }

  content() {
    return (
      <div className="container">
        <div className="LowseekaiAdvertisingAdmin">
          <h2>{app.translator.trans('lowseekai-advertising.admin.title')}</h2>
          <p className="helpText">{app.translator.trans('lowseekai-advertising.admin.help')}</p>
          {this.configLoaded ? this.configForm() : <LoadingIndicator />}
          <div className="LowseekaiAdvertisingAdmin-review">
            <div className="LowseekaiAdvertisingAdmin-heading">
              <h3>{app.translator.trans('lowseekai-advertising.admin.pending_title')}</h3>
            </div>
            {this.loadingAds ? <LoadingIndicator /> : this.ads.length ? this.ads.map((ad) => this.adRow(ad)) : <p className="helpText">{app.translator.trans('lowseekai-advertising.admin.empty')}</p>}
          </div>
        </div>
      </div>
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
        {this.numberField('sidebarPricePerMonth', 'lowseekai-advertising.admin.sidebar_price')}
        {this.numberField('topPricePerMonth', 'lowseekai-advertising.admin.top_price')}
        {this.numberField('maxImageSizeKb', 'lowseekai-advertising.admin.max_image_size')}
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.admin.currency_name')}</label>
          <input className="FormControl" value={this.config.currencyName} oninput={(event: any) => (this.config.currencyName = event.target.value)} />
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.admin.currency_icon')}</label>
          <input className="FormControl" value={this.config.currencyIcon} oninput={(event: any) => (this.config.currencyIcon = event.target.value)} />
        </div>
        <div className="Form-group Form-controls">
          <Button type="button" className="Button Button--primary" icon="fas fa-save" loading={this.savingConfig} disabled={this.savingConfig} onclick={() => this.saveConfig()}>
            {app.translator.trans('lowseekai-advertising.admin.save')}
          </Button>
        </div>
      </Form>
    );
  }

  numberField(key: 'sidebarPricePerMonth' | 'topPricePerMonth' | 'maxImageSizeKb', labelKey: string) {
    return (
      <div className="Form-group">
        <label>{app.translator.trans(labelKey)}</label>
        <input type="number" min="0" className="FormControl" value={this.config[key]} oninput={(event: any) => (this.config[key] = Number(event.target.value))} />
      </div>
    );
  }

  adRow(ad: AdRecord) {
    const busy = this.savingAd === ad.id;
    return (
      <div className="LowseekaiAdvertisingAdmin-ad" key={ad.id}>
        <img src={ad.imageUrl} alt={ad.title} />
        <div className="LowseekaiAdvertisingAdmin-adBody">
          <strong>{ad.title}</strong>
          <span>{ad.user?.displayName || ad.user?.username || '-'} · {ad.slotLabel} · {ad.durationLabel} · {ad.totalPrice} {this.config.currencyName}</span>
          <a href={ad.targetUrl} target="_blank" rel="noopener noreferrer">{ad.targetUrl}</a>
          <textarea className="FormControl" placeholder={app.translator.trans('lowseekai-advertising.admin.review_note')} value={ad.reviewNote || ''} oninput={(event: any) => (ad.reviewNote = event.target.value)} />
          <div className="LowseekaiAdvertisingAdmin-actions">
            <Button className="Button Button--primary" icon="fas fa-check" loading={busy} disabled={busy} onclick={() => this.updateAd(ad, 'approved')}>
              {app.translator.trans('lowseekai-advertising.admin.approve')}
            </Button>
            <Button className="Button Button--danger" icon="fas fa-times" loading={busy} disabled={busy} onclick={() => this.updateAd(ad, 'rejected')}>
              {app.translator.trans('lowseekai-advertising.admin.reject')}
            </Button>
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
      this.ads = this.ads.filter((item) => item.id !== ad.id);
      app.alerts.show({ type: 'success' }, app.translator.trans(`lowseekai-advertising.admin.${status === 'approved' ? 'approved' : 'rejected'}`));
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.savingAd = null;
      m.redraw();
    }
  }

  errorMessage(error: any) {
    return error?.response?.errors?.[0]?.detail || error?.response?.errors?.[0]?.title || app.translator.trans('lowseekai-advertising.error');
  }
}

app.initializers.add('lowseekai/advertising/admin', () => {
  app.registry
    .for('lowseekai-advertising')
    .registerPage(AdvertisingSettingsPage)
    .registerPermission(
      { icon: 'fas fa-bullhorn', label: app.translator.trans('lowseekai-advertising.admin.permissions.submit'), permission: 'lowseekai-advertising.submit', allowGuest: false },
      'view'
    )
    .registerPermission(
      { icon: 'fas fa-gavel', label: app.translator.trans('lowseekai-advertising.admin.permissions.manage'), permission: 'lowseekai-advertising.manage', allowGuest: false },
      'moderate'
    );
});
