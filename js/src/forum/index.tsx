import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Page from 'flarum/common/components/Page';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexPage from 'flarum/forum/components/IndexPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

type DurationPlan = { key: string; label: string; months: number; days: number };
type AdRecord = {
  id: number;
  slotKey: string;
  slotLabel: string;
  title: string;
  imageUrl: string;
  targetUrl: string;
  durationLabel: string;
  totalPrice: number;
  status: string;
  reviewNote?: string | null;
};

type AdvertisingNotificationData = {
  title?: string;
  totalPrice?: number;
  status?: string;
  reviewNote?: string | null;
};

const DEFAULT_PLANS: DurationPlan[] = [
  { key: '1_month', label: '1 个月', months: 1, days: 30 },
  { key: '3_months', label: '3 个月', months: 3, days: 90 },
  { key: '6_months', label: '半年', months: 6, days: 180 },
  { key: '1_year', label: '一年', months: 12, days: 365 },
];

class AdCard extends Component<{ ad: AdRecord }> {
  view() {
    const ad = this.attrs.ad;
    return (
      <a className="LowseekaiAdvertising-card" href={ad.targetUrl} target="_blank" rel="noopener noreferrer">
        <img src={ad.imageUrl} alt={ad.title} />
        <span>{ad.title}</span>
      </a>
    );
  }
}

class AdPendingReviewNotification extends Notification {
  icon() {
    return 'fas fa-bullhorn';
  }

  href() {
    return app.route('lowseekai-advertising.index');
  }

  content() {
    const data = this.attrs.notification.content<AdvertisingNotificationData>() || {};
    return app.translator.trans('lowseekai-advertising.forum.notification_pending', {
      user: this.attrs.notification.fromUser(),
      title: data.title || '',
      price: data.totalPrice || 0,
    });
  }

  excerpt() {
    return null;
  }
}

class AdReviewedNotification extends Notification {
  icon() {
    const data = this.attrs.notification.content<AdvertisingNotificationData>() || {};
    return data.status === 'approved' ? 'fas fa-check-circle' : 'fas fa-gavel';
  }

  href() {
    return app.route('lowseekai-advertising.index');
  }

  content() {
    const data = this.attrs.notification.content<AdvertisingNotificationData>() || {};
    const key = data.status === 'approved'
      ? 'lowseekai-advertising.forum.notification_approved'
      : data.reviewNote
        ? 'lowseekai-advertising.forum.notification_rejected_with_note'
        : 'lowseekai-advertising.forum.notification_rejected';

    return app.translator.trans(key, {
      title: data.title || '',
      price: data.totalPrice || 0,
      note: data.reviewNote || '',
    });
  }

  excerpt() {
    return null;
  }
}

class AdvertisingPage extends Page {
  private ads: AdRecord[] = [];
  private myAds: AdRecord[] = [];
  private loading = true;
  private submitting = false;
  private uploadedPath = '';
  private uploadedFileName = '';
  private fileInput: HTMLInputElement | null = null;
  private refreshTimer: number | null = null;
  private form = { slotKey: 'sidebar', title: '', targetUrl: '', durationPlan: '1_month' };

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
    this.refreshTimer = window.setInterval(() => this.load(), 30000);
  }

  onremove() {
    if (this.refreshTimer !== null) window.clearInterval(this.refreshTimer);
    this.refreshTimer = null;
  }

  apiUrl(path: string) {
    return `${app.forum.attribute('apiUrl')}${path}`;
  }

  plans(): DurationPlan[] {
    const plans = app.forum.attribute('lowseekaiAdvertisingDurationPlans') as DurationPlan[] | undefined;
    return Array.isArray(plans) && plans.length ? plans : DEFAULT_PLANS;
  }

  selectedPlan(): DurationPlan {
    return this.plans().find((plan) => plan.key === this.form.durationPlan) || this.plans()[0];
  }

  pricePerMonth(): number {
    const key = this.form.slotKey === 'top' ? 'lowseekaiAdvertisingTopPricePerMonth' : 'lowseekaiAdvertisingSidebarPricePerMonth';
    return Number(app.forum.attribute(key) || 0);
  }

  totalPrice(): number {
    return this.pricePerMonth() * this.selectedPlan().months;
  }

  pointBalance(): number {
    return Number(app.forum.attribute('lowseekaiAdvertisingPointBalance') || 0);
  }

  async load() {
    this.loading = true;
    try {
      const [publicResponse, myResponse] = (await Promise.all([
        app.request({ method: 'GET', url: this.apiUrl('/advertising/public/ads') }),
        app.session.user ? app.request({ method: 'GET', url: this.apiUrl('/advertising/me/ads') }) : Promise.resolve({ data: [] }),
      ])) as any[];
      this.ads = Array.isArray(publicResponse.data) ? publicResponse.data : [];
      this.myAds = Array.isArray(myResponse.data) ? myResponse.data : [];
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  view() {
    return (
      <PageStructure className="LowseekaiAdvertisingPage" sidebar={() => <IndexSidebar />}>
        <div className="LowseekaiAdvertisingPage-content">
          <header className="Hero LowseekaiAdvertisingHero">
            <div className="container">
              <h1>
                <i className="fas fa-bullhorn" /> {app.translator.trans('lowseekai-advertising.forum.title')}
              </h1>
            </div>
          </header>
          {this.content()}
        </div>
      </PageStructure>
    );
  }

  content() {
    if (this.loading) return <LoadingIndicator />;
    return (
      <div className="container">
        <section className="LowseekaiAdvertising-list">
          <div className="LowseekaiAdvertising-sectionHeading">
            <h2>{app.translator.trans('lowseekai-advertising.forum.active_ads')}</h2>
          </div>
          {this.ads.length ? this.ads.map((ad) => <AdCard ad={ad} key={ad.id} />) : <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_ads')}</p>}
        </section>
        {app.forum.attribute('lowseekaiAdvertisingCanSubmit') ? this.applyForm() : <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_permission')}</p>}
        {app.session.user ? this.myAdsView() : null}
      </div>
    );
  }

  applyForm() {
    const currency = app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分';
    const balance = this.pointBalance();
    const total = this.totalPrice();
    const insufficient = total > balance;

    return (
      <section className="LowseekaiAdvertising-apply">
        <h2>{app.translator.trans('lowseekai-advertising.forum.apply_title')}</h2>
        <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.apply_help')}</p>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.slot')}</label>
          <select className="FormControl" value={this.form.slotKey} onchange={(event: any) => (this.form.slotKey = event.target.value)}>
            <option value="sidebar">{app.translator.trans('lowseekai-advertising.forum.sidebar')}</option>
            <option value="top">{app.translator.trans('lowseekai-advertising.forum.top')}</option>
          </select>
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.title_label')}</label>
          <input className="FormControl" value={this.form.title} oninput={(event: any) => (this.form.title = event.target.value)} />
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.url')}</label>
          <input className="FormControl" type="url" value={this.form.targetUrl} oninput={(event: any) => (this.form.targetUrl = event.target.value)} placeholder="https://" />
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.image')}</label>
          <input
            className="LowseekaiAdvertising-fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            oncreate={(vnode: any) => (this.fileInput = vnode.dom as HTMLInputElement)}
            onchange={(event: any) => this.upload(event.target.files?.[0])}
          />
          <div className="LowseekaiAdvertising-filePicker">
            <Button type="button" className="Button Button--secondary" icon="fas fa-image" onclick={() => this.fileInput?.click()}>
              {app.translator.trans('lowseekai-advertising.forum.choose_file')}
            </Button>
            <span className="LowseekaiAdvertising-fileName">{this.uploadedFileName || app.translator.trans('lowseekai-advertising.forum.no_file')}</span>
            {this.uploadedPath ? (
              <Button
                type="button"
                className="Button Button--icon Button--link LowseekaiAdvertising-fileRemove"
                icon="fas fa-times"
                aria-label={app.translator.trans('lowseekai-advertising.forum.remove_file')}
                title={app.translator.trans('lowseekai-advertising.forum.remove_file')}
                onclick={() => this.clearUploadedFile()}
              />
            ) : null}
          </div>
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.duration')}</label>
          <select className="FormControl" value={this.form.durationPlan} onchange={(event: any) => (this.form.durationPlan = event.target.value)}>
            {this.plans().map((plan) => <option value={plan.key}>{plan.label}</option>)}
          </select>
          <p className="helpText">
            {app.translator.trans('lowseekai-advertising.forum.price_help', { price: total, balance, currency })}
          </p>
          {insufficient ? <p className="helpText LowseekaiAdvertising-insufficient">{app.translator.trans('lowseekai-advertising.forum.insufficient_points')}</p> : null}
        </div>
        <Button
          className="Button Button--primary"
          icon="fas fa-paper-plane"
          loading={this.submitting}
          disabled={this.submitting || insufficient}
          onclick={() => this.submit()}
        >
          {app.translator.trans('lowseekai-advertising.forum.submit')}
        </Button>
      </section>
    );
  }

  myAdsView() {
    return (
      <section className="LowseekaiAdvertising-my">
        <h2>{app.translator.trans('lowseekai-advertising.forum.my_ads')}</h2>
        {this.myAds.length ? this.myAds.map((ad) => (
          <div className="LowseekaiAdvertising-myRow" key={ad.id}>
            <img src={ad.imageUrl} alt={ad.title} />
            <div>
              <strong>{ad.title}</strong>
              <span>{this.statusLabel(ad.status)}{ad.reviewNote ? ` · ${ad.reviewNote}` : ''}</span>
            </div>
          </div>
        )) : <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_my_ads')}</p>}
      </section>
    );
  }

  async upload(file?: File) {
    if (!file) return;
    const body = new FormData();
    body.append('image', file);
    try {
      const response: any = await app.request({ method: 'POST', url: this.apiUrl('/advertising/upload-image'), body });
      this.uploadedPath = response.data?.path || '';
      this.uploadedFileName = file.name;
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.forum.upload_success'));
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    }
    m.redraw();
  }

  clearUploadedFile() {
    this.uploadedPath = '';
    this.uploadedFileName = '';
    if (this.fileInput) this.fileInput.value = '';
    m.redraw();
  }

  async submit() {
    const total = this.totalPrice();
    if (total > this.pointBalance()) {
      app.alerts.show({ type: 'error' }, app.translator.trans('lowseekai-advertising.forum.insufficient_points'));
      return;
    }
    if (!this.uploadedPath) {
      app.alerts.show({ type: 'error' }, app.translator.trans('lowseekai-advertising.forum.image_required'));
      return;
    }
    this.submitting = true;
    try {
      await app.request({
        method: 'POST',
        url: this.apiUrl('/advertising/ads'),
        body: { data: { attributes: { ...this.form, imagePath: this.uploadedPath } } },
      });
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.forum.pending_success'));
      this.form = { slotKey: 'sidebar', title: '', targetUrl: '', durationPlan: '1_month' };
      this.uploadedPath = '';
      this.uploadedFileName = '';
      if (this.fileInput) this.fileInput.value = '';
      await this.load();
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.submitting = false;
      m.redraw();
    }
  }

  statusLabel(status: string) {
    const key = ['pending', 'approved', 'rejected', 'expired'].includes(status) ? status : 'pending';
    return app.translator.trans(`lowseekai-advertising.forum.status_${key}`);
  }

  errorMessage(error: any) {
    return error?.response?.errors?.[0]?.detail || error?.response?.errors?.[0]?.title || app.translator.trans('lowseekai-advertising.error');
  }
}

class AdvertisingSidebar extends Component {
  private ads: AdRecord[] = [];
  private loaded = false;

  oninit() {
    this.load();
  }

  async load() {
    try {
      const response: any = await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/advertising/public/ads?slot=sidebar` });
      this.ads = Array.isArray(response.data) ? response.data : [];
    } finally {
      this.loaded = true;
      m.redraw();
    }
  }

  view() {
    if (!this.loaded || !this.ads.length) return null;
    return <div className="LowseekaiAdvertisingSidebar">{this.ads.slice(0, 5).map((ad) => <AdCard ad={ad} key={ad.id} />)}</div>;
  }
}

class AdvertisingTop extends Component {
  private ads: AdRecord[] = [];
  private loaded = false;

  oninit() {
    this.load();
  }

  async load() {
    try {
      const response: any = await app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/advertising/public/ads?slot=top` });
      this.ads = Array.isArray(response.data) ? response.data : [];
    } catch {
      this.ads = [];
    } finally {
      this.loaded = true;
      m.redraw();
    }
  }

  view() {
    if (!this.loaded || !this.ads.length) return null;
    return <div className="LowseekaiAdvertisingTop">{this.ads.slice(0, 3).map((ad) => <AdCard ad={ad} key={ad.id} />)}</div>;
  }
}

app.initializers.add('lowseekai/advertising/forum', () => {
  app.routes['lowseekai-advertising.index'] = { path: '/advertising', component: AdvertisingPage };
  app.notificationComponents.lowseekaiAdvertisingAdPendingReview = AdPendingReviewNotification;
  app.notificationComponents.lowseekaiAdvertisingAdReviewed = AdReviewedNotification;

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', (items: any) => {
    items.add('lowseekaiAdvertisingAdPendingReview', {
      name: 'lowseekaiAdvertisingAdPendingReview',
      icon: 'fas fa-bullhorn',
      label: app.translator.trans('lowseekai-advertising.forum.notification_pending_label'),
    });
    items.add('lowseekaiAdvertisingAdReviewed', {
      name: 'lowseekaiAdvertisingAdReviewed',
      icon: 'fas fa-gavel',
      label: app.translator.trans('lowseekai-advertising.forum.notification_reviewed_label'),
    });
  });

  extend(IndexPage.prototype, 'contentItems', (items: any) => {
    items.add('lowseekai-advertising-top', <AdvertisingTop />, 105);
  });
  extend(IndexSidebar.prototype, 'navItems', (items: any) => {
    items.add('lowseekai-advertising', <LinkButton href={app.route('lowseekai-advertising.index')} icon="fas fa-bullhorn">
      {app.translator.trans('lowseekai-advertising.forum.nav')}
    </LinkButton>, 95);
  });
  extend(IndexSidebar.prototype, 'items', (items: any) => {
    items.add('lowseekai-advertising-sidebar', <AdvertisingSidebar />, -20);
  });
});
