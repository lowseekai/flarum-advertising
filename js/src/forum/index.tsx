import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Page from 'flarum/common/components/Page';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import IndexPage from 'flarum/forum/components/IndexPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import PageStructure from 'flarum/forum/components/PageStructure';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

type DurationPlan = { key: string; label: string; months: number; days: number };
type SlotPosition = { position: number; available: boolean };
type SlotConfig = {
  key: string;
  label: string;
  enabled: boolean;
  capacity: number;
  displayCount?: number;
  pricePerMonth: number;
  positions?: SlotPosition[];
};
type AdRecord = {
  id: number;
  slotKey: string;
  slotLabel: string;
  slotPosition?: number | null;
  title: string;
  imageUrl: string;
  targetUrl: string;
  durationLabel: string;
  totalPrice: number;
  renewalPrice: number;
  status: string;
  reviewNote?: string | null;
  startsAt?: string | null;
  endsAt?: string | null;
};
type AdvertisingNotificationData = {
  title?: string;
  totalPrice?: number;
  status?: string;
  reviewNote?: string | null;
  slotLabel?: string;
  slotPosition?: number | null;
  position?: number | null;
  startsAt?: string | null;
  endsAt?: string | null;
};

const TOP_SLOT_COUNT = 4;

const DEFAULT_PLANS: DurationPlan[] = [
  { key: '1_month', label: '1 个月', months: 1, days: 30 },
  { key: '3_months', label: '3 个月', months: 3, days: 90 },
  { key: '6_months', label: '半年', months: 6, days: 180 },
  { key: '1_year', label: '一年', months: 12, days: 365 },
];

function fallbackSlots(): SlotConfig[] {
  return [
    {
      key: 'top',
      label: '顶部广告位',
      enabled: Boolean(app.forum.attribute('lowseekaiAdvertisingTopEnabled') ?? true),
      capacity: TOP_SLOT_COUNT,
      displayCount: TOP_SLOT_COUNT,
      pricePerMonth: Number(app.forum.attribute('lowseekaiAdvertisingTopPricePerMonth') || 0),
    },
    {
      key: 'left_sidebar',
      label: '左侧栏广告位',
      enabled: Boolean(app.forum.attribute('lowseekaiAdvertisingLeftSidebarEnabled') ?? false),
      capacity: Number(app.forum.attribute('lowseekaiAdvertisingLeftSidebarSlots') || 10),
      displayCount: Number(app.forum.attribute('lowseekaiAdvertisingLeftSidebarDisplaySlots') || 3),
      pricePerMonth: Number(app.forum.attribute('lowseekaiAdvertisingLeftSidebarPricePerMonth') || 0),
    },
    {
      key: 'right_sidebar',
      label: '右侧栏广告位',
      enabled: Boolean(
        app.forum.attribute('lowseekaiAdvertisingRightSidebarEnabled') ??
          app.forum.attribute('lowseekaiAdvertisingSidebarEnabled') ??
          true
      ),
      capacity: Number(
        app.forum.attribute('lowseekaiAdvertisingRightSidebarSlots') ||
          app.forum.attribute('lowseekaiAdvertisingSidebarSlots') ||
          10
      ),
      displayCount: Number(app.forum.attribute('lowseekaiAdvertisingRightSidebarDisplaySlots') || 3),
      pricePerMonth: Number(
        app.forum.attribute('lowseekaiAdvertisingRightSidebarPricePerMonth') ||
          app.forum.attribute('lowseekaiAdvertisingSidebarPricePerMonth') ||
          0
      ),
    },
  ];
}

class AdCard extends Component<{ ad: AdRecord; placement?: string }> {
  view() {
    const ad = this.attrs.ad;
    const placement = (this.attrs.placement || ad.slotKey).replace('_', '-');

    return (
      <a
        className={`LowseekaiAdvertising-card LowseekaiAdvertising-card--${placement}`}
        href={ad.targetUrl}
        target="_blank"
        rel="noopener noreferrer"
        title={ad.title}
      >
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
    const key =
      data.status === 'approved'
        ? 'lowseekai-advertising.forum.notification_approved'
        : data.reviewNote
        ? 'lowseekai-advertising.forum.notification_rejected_with_note'
        : 'lowseekai-advertising.forum.notification_rejected';

    return app.translator.trans(key, {
      title: data.title || '',
      price: data.totalPrice || 0,
      slot: data.slotLabel || '',
      position: data.position || data.slotPosition || '',
      startsAt: data.startsAt || '',
      endsAt: data.endsAt || '',
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
  private slotConfigs: SlotConfig[] = fallbackSlots();
  private loading = true;
  private submitting = false;
  private uploading = false;
  private renewingAd: number | null = null;
  private uploadedPath = '';
  private uploadedFileName = '';
  private fileInput: HTMLInputElement | null = null;
  private refreshTimer: number | null = null;
  private form = { slotKey: 'right_sidebar', slotPosition: 1, title: '', targetUrl: '', durationPlan: '1_month' };

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.setInitialSlot();
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

  setInitialSlot() {
    const first =
      this.slotConfigs.find((slot) => slot.key === 'right_sidebar' && slot.enabled) ||
      this.slotConfigs.find((slot) => slot.enabled) ||
      this.slotConfigs[0];
    if (first) this.form.slotKey = first.key;
  }

  plans(): DurationPlan[] {
    const plans = app.forum.attribute('lowseekaiAdvertisingDurationPlans') as DurationPlan[] | undefined;

    return Array.isArray(plans) && plans.length ? plans : DEFAULT_PLANS;
  }

  selectedPlan(): DurationPlan {
    return this.plans().find((plan) => plan.key === this.form.durationPlan) || this.plans()[0];
  }

  selectedSlot(): SlotConfig {
    return this.slotConfigs.find((slot) => slot.key === this.form.slotKey) || fallbackSlots()[0];
  }

  pricePerMonth(): number {
    return this.selectedSlot().pricePerMonth;
  }

  totalPrice(): number {
    return this.pricePerMonth() * this.selectedPlan().months;
  }

  pointBalance(): number {
    return Number(app.forum.attribute('lowseekaiAdvertisingPointBalance') || 0);
  }

  availablePositions(): SlotPosition[] {
    const positions = this.selectedSlot().positions;

    if (positions?.length) return positions;

    return Array.from({ length: this.selectedSlot().capacity }, (_, index) => ({
      position: index + 1,
      available: true,
    }));
  }

  setSlot(slotKey: string) {
    this.form.slotKey = slotKey;
    const firstAvailable = this.availablePositions().find((position) => position.available);
    this.form.slotPosition = firstAvailable?.position || 1;
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
      if (Array.isArray(publicResponse.meta?.slots) && publicResponse.meta.slots.length) {
        this.slotConfigs = publicResponse.meta.slots.map((slot: SlotConfig) => {
          if (slot.key !== 'top') return slot;

          const positions = Array.from({ length: TOP_SLOT_COUNT }, (_, index) => {
            const existing = slot.positions?.find((position) => position.position === index + 1);

            return existing || { position: index + 1, available: true };
          });

          return { ...slot, capacity: TOP_SLOT_COUNT, positions };
        });
      }
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
        <header className="Hero LowseekaiAdvertisingHero">
          <div className="container">
            <h1>
              <i className="fas fa-bullhorn" /> {app.translator.trans('lowseekai-advertising.forum.title')}
            </h1>
          </div>
        </header>
        {this.content()}
      </PageStructure>
    );
  }

  content() {
    if (this.loading) return <LoadingIndicator />;

    return (
      <div className="LowseekaiAdvertisingPage-content">
        {app.forum.attribute('lowseekaiAdvertisingCanSubmit') ? (
          this.applyForm()
        ) : (
          <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_permission')}</p>
        )}
        {app.session.user ? this.myAdsView() : null}
        <section className="LowseekaiAdvertising-list">
          <div className="LowseekaiAdvertising-sectionHeading">
            <h2>{app.translator.trans('lowseekai-advertising.forum.active_ads')}</h2>
          </div>
          {this.ads.length ? (
            this.ads.map((ad) => <AdCard ad={ad} key={ad.id} />)
          ) : (
            <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_ads')}</p>
          )}
        </section>
      </div>
    );
  }

  applyForm() {
    const currency = app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分';
    const balance = this.pointBalance();
    const total = this.totalPrice();
    const insufficient = total > balance;
    const positions = this.availablePositions();
    const noPositions = !positions.some((position) => position.available);

    return (
      <section className="LowseekaiAdvertising-apply">
        <h2>{app.translator.trans('lowseekai-advertising.forum.apply_title')}</h2>
        <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.apply_help')}</p>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.slot')}</label>
          <select className="FormControl" value={this.form.slotKey} onchange={(event: any) => this.setSlot(event.target.value)}>
            {this.slotConfigs
              .filter((slot) => slot.enabled)
              .map((slot) => (
                <option value={slot.key}>{slot.label}</option>
              ))}
          </select>
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.position')}</label>
          <select
            className="FormControl"
            value={this.form.slotPosition}
            onchange={(event: any) => (this.form.slotPosition = Number(event.target.value))}
            disabled={noPositions}
          >
            {positions.map((position) => (
              <option value={position.position} disabled={!position.available}>
                {app.translator.trans('lowseekai-advertising.forum.position_option', { position: position.position })}
                {!position.available ? `（${app.translator.trans('lowseekai-advertising.forum.position_unavailable')}）` : ''}
              </option>
            ))}
          </select>
          {noPositions ? <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_positions')}</p> : null}
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.title_label')}</label>
          <input className="FormControl" value={this.form.title} oninput={(event: any) => (this.form.title = event.target.value)} />
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.url')}</label>
          <input
            className="FormControl"
            type="url"
            value={this.form.targetUrl}
            oninput={(event: any) => (this.form.targetUrl = event.target.value)}
            placeholder="https://"
          />
        </div>
        <div className="Form-group">
          <label>{app.translator.trans('lowseekai-advertising.forum.image')}</label>
          <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.image_formats')}</p>
          <input
            className="LowseekaiAdvertising-fileInput"
            type="file"
            accept="image/gif,image/jpeg,image/png"
            oncreate={(vnode: any) => (this.fileInput = vnode.dom as HTMLInputElement)}
            onchange={(event: any) => this.upload(event.target.files?.[0])}
          />
          <div className="LowseekaiAdvertising-filePicker">
            <Button
              type="button"
              className="Button Button--secondary"
              icon="fas fa-image"
              loading={this.uploading}
              disabled={this.uploading}
              onclick={() => this.fileInput?.click()}
            >
              {app.translator.trans('lowseekai-advertising.forum.choose_file')}
            </Button>
            {this.uploading ? (
              <span className="helpText LowseekaiAdvertising-uploadStatus">{app.translator.trans('lowseekai-advertising.forum.uploading')}</span>
            ) : null}
            <span className="LowseekaiAdvertising-fileName">
              {this.uploadedFileName || app.translator.trans('lowseekai-advertising.forum.no_file')}
            </span>
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
            {this.plans().map((plan) => (
              <option value={plan.key}>{plan.label}</option>
            ))}
          </select>
          <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.price_help', { price: total, balance, currency })}</p>
          {insufficient ? (
            <p className="helpText LowseekaiAdvertising-insufficient">{app.translator.trans('lowseekai-advertising.forum.insufficient_points')}</p>
          ) : null}
        </div>
        <Button
          className="Button Button--primary"
          icon="fas fa-paper-plane"
          loading={this.submitting}
          disabled={this.submitting || insufficient || noPositions}
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
        {this.myAds.length ? (
          this.myAds.map((ad) => (
            <div className="LowseekaiAdvertising-myRow" key={ad.id}>
              <img src={ad.imageUrl} alt={ad.title} />
              <div className="LowseekaiAdvertising-myDetails">
                <strong>{ad.title}</strong>
                <span>
                  {this.statusLabel(ad.status)}
                  {ad.reviewNote ? ` · ${ad.reviewNote}` : ''}
                </span>
                <span>
                  {ad.slotLabel} · {app.translator.trans('lowseekai-advertising.forum.position_option', { position: ad.slotPosition || '-' })} ·{' '}
                  {ad.durationLabel} · {ad.totalPrice} {app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分'}
                </span>
                {ad.startsAt ? (
                  <span>{app.translator.trans('lowseekai-advertising.forum.starts_at', { date: this.formatDate(ad.startsAt) })}</span>
                ) : null}
                {ad.endsAt ? <span>{app.translator.trans('lowseekai-advertising.forum.ends_at', { date: this.formatDate(ad.endsAt) })}</span> : null}
                {ad.status === 'approved' && app.forum.attribute('lowseekaiAdvertisingRenewalEnabled') ? (
                  <Button
                    type="button"
                    className="Button Button--secondary LowseekaiAdvertising-renewButton"
                    icon="fas fa-redo"
                    loading={this.renewingAd === ad.id}
                    disabled={this.renewingAd !== null}
                    onclick={() => this.renew(ad)}
                  >
                    {app.translator.trans('lowseekai-advertising.forum.renew')}
                  </Button>
                ) : null}
              </div>
            </div>
          ))
        ) : (
          <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_my_ads')}</p>
        )}
      </section>
    );
  }

  async upload(file?: File) {
    if (!file) return;

    this.uploading = true;
    m.redraw();

    const body = new FormData();
    body.append('image', file);

    try {
      const response: any = await app.request({ method: 'POST', url: this.apiUrl('/advertising/upload-image'), body });
      this.uploadedPath = response.data?.path || '';
      this.uploadedFileName = file.name;
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.forum.upload_success'));
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.uploading = false;
      m.redraw();
    }
  }

  async renew(ad: AdRecord) {
    if (
      !window.confirm(
        String(
          app.translator.trans('lowseekai-advertising.forum.renew_confirm', {
            title: ad.title,
            price: ad.renewalPrice || ad.totalPrice,
          })
        )
      )
    ) {
      return;
    }

    this.renewingAd = ad.id;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: this.apiUrl(`/advertising/ads/${ad.id}/renew`),
      });
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.forum.renew_success'));
      await this.load();
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.renewingAd = null;
      m.redraw();
    }
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
      this.form = { slotKey: this.form.slotKey, slotPosition: this.form.slotPosition, title: '', targetUrl: '', durationPlan: '1_month' };
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
    const key = ['pending', 'approved', 'rejected', 'expired', 'hidden'].includes(status) ? status : 'pending';

    return app.translator.trans(`lowseekai-advertising.forum.status_${key}`);
  }

  formatDate(value: string) {
    return new Date(value).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' });
  }

  errorMessage(error: any) {
    return error?.response?.errors?.[0]?.detail || error?.response?.errors?.[0]?.title || app.translator.trans('lowseekai-advertising.error');
  }
}

class AdvertisingSlotGrid extends Component<{ slot: 'left_sidebar' | 'right_sidebar' | 'top' }> {
  private ads: AdRecord[] = [];
  private config: SlotConfig | null = null;
  private loaded = false;
  private slot: 'left_sidebar' | 'right_sidebar' | 'top' | null = null;

  oninit(vnode: Mithril.Vnode<{ slot: 'left_sidebar' | 'right_sidebar' | 'top' }, this>) {
    super.oninit(vnode);
    this.slot = this.attrs?.slot || null;
    if (!this.slot) {
      this.loaded = true;
      return;
    }

    this.config = fallbackSlots().find((slot) => slot.key === this.slot) || null;
    this.load();
  }

  async load() {
    const slot = this.slot;
    if (!slot) return;

    try {
      const response: any = await app.request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/advertising/public/ads?slot=${slot}`,
      });
      this.ads = Array.isArray(response.data) ? response.data : [];
      this.config = (response.meta?.slots || []).find((config: SlotConfig) => config.key === slot) || this.config || null;
    } catch {
      this.ads = [];
    } finally {
      this.loaded = true;
      m.redraw();
    }
  }

  view() {
    const slot = this.slot;
    if (!slot || !app.forum.attribute('lowseekaiAdvertisingEnabled') || !this.config?.enabled) {
      return null;
    }

    const capacity = slot === 'top' ? TOP_SLOT_COUNT : this.config.capacity;
    const adsByPosition = new Map<number, AdRecord>();
    this.ads.forEach((ad, index) => {
      const position = Number(ad.slotPosition) > 0 ? Number(ad.slotPosition) : index + 1;

      if (position <= capacity && !adsByPosition.has(position)) {
        adsByPosition.set(position, ad);
      }
    });

    const visibleEmptySlotLimit =
      slot === 'top' ? capacity : Math.max(1, Math.min(capacity, Number(this.config.displayCount) || 3));
    let visibleEmptySlots = 0;
    const slotItems = Array.from({ length: capacity }, (_, index) => {
      const position = index + 1;
      const ad = adsByPosition.get(position) || null;

      return { ad, position };
    }).filter(({ ad }) => {
      if (ad) return true;

      visibleEmptySlots += 1;
      return slot === 'top' || visibleEmptySlots <= visibleEmptySlotLimit;
    });
    const hiddenEmptySlots = slot !== 'top' ? Math.max(0, capacity - adsByPosition.size - visibleEmptySlotLimit) : 0;
    const slotClass = slot.replace('_', '-');

    return (
      <div className={`LowseekaiAdvertising-${slotClass}`}>
        {slotItems.map(({ ad, position }) =>
          ad ? (
            <AdCard ad={ad} placement={slot} key={ad.id} />
          ) : (
            <a
              className={`LowseekaiAdvertising-placeholder LowseekaiAdvertising-placeholder--${slotClass}`}
              href={app.route('lowseekai-advertising.index')}
              title={app.translator.trans('lowseekai-advertising.forum.apply_slot')}
              aria-label={app.translator.trans('lowseekai-advertising.forum.apply_slot')}
              key={`empty-${position}`}
            >
              <i className="fas fa-plus" aria-hidden="true" />
              <span>{app.translator.trans('lowseekai-advertising.forum.apply_slot')}</span>
            </a>
          )
        )}
        {slot !== 'top' && hiddenEmptySlots > 0 ? (
          <a className="LowseekaiAdvertising-viewAll" href={app.route('lowseekai-advertising.index')}>
            {app.translator.trans('lowseekai-advertising.forum.view_all_slots')}
          </a>
        ) : null}
      </div>
    );
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

  extend(IndexSidebar.prototype, 'items', (items: any) => {
    if (app.current?.get('routeName') !== 'index') return;

    items.add(
      'lowseekai-advertising-left-sidebar',
      <AdvertisingSlotGrid slot="left_sidebar" />,
      -1000
    );
  });

  extend(IndexPage.prototype, 'contentItems', (items: any) => {
    items.add('lowseekai-advertising-right-rail', <AdvertisingSlotGrid slot="right_sidebar" />, 80);
  });

  extend(PageStructure.prototype, 'mainItems', (items: any) => {
    if (app.current?.get('routeName') !== 'index') return;

    items.add(
      'lowseekai-advertising-top',
      <div className="container LowseekaiAdvertising-top-container">
        <AdvertisingSlotGrid slot="top" />
      </div>,
      50
    );
  });

  extend(IndexSidebar.prototype, 'navItems', (items: any) => {
    items.add(
      'lowseekai-advertising',
      <LinkButton href={app.route('lowseekai-advertising.index')} icon="fas fa-bullhorn">
        {app.translator.trans('lowseekai-advertising.forum.nav')}
      </LinkButton>,
      95
    );
  });
});
