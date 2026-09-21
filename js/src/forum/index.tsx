import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Page from 'flarum/common/components/Page';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
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
  availableCount?: number;
  isFull?: boolean;
  reservable?: boolean;
  earliestReleaseAt?: string | null;
  reservationQueueCount?: number;
  myReservationQueuePosition?: number | null;
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
  canReserve?: boolean;
  renewalPrice: number;
  status: string;
  isReservation?: boolean;
  reservedAt?: string | null;
  reservationEstimatedStartAt?: string | null;
  reservationWaitUntil?: string | null;
  reservationDeferredCount?: number;
  reservationCancelledAt?: string | null;
  autoRenewEnabled: boolean;
  autoRenewStatus: string;
  autoRenewPrice?: number | null;
  autoRenewLastAttemptAt?: string | null;
  autoRenewFailureReason?: string | null;
  autoRenewDisabledAt?: string | null;
  nextAutoRenewAt?: string | null;
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
  estimatedStartAt?: string | null;
  waitUntil?: string | null;
  event?: string;
  amount?: number;
  reason?: string;
};

const TOP_SLOT_COUNT = 4;
const MOBILE_AD_BREAKPOINT = 768;

function isMobileViewport() {
  return typeof window !== 'undefined' && window.matchMedia(`(max-width: ${MOBILE_AD_BREAKPOINT}px)`).matches;
}

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
        app.forum.attribute('lowseekaiAdvertisingRightSidebarEnabled') ?? app.forum.attribute('lowseekaiAdvertisingSidebarEnabled') ?? true
      ),
      capacity: Number(app.forum.attribute('lowseekaiAdvertisingRightSidebarSlots') || app.forum.attribute('lowseekaiAdvertisingSidebarSlots') || 10),
      displayCount: Number(app.forum.attribute('lowseekaiAdvertisingRightSidebarDisplaySlots') || 3),
      pricePerMonth: Number(
        app.forum.attribute('lowseekaiAdvertisingRightSidebarPricePerMonth') || app.forum.attribute('lowseekaiAdvertisingSidebarPricePerMonth') || 0
      ),
    },
  ];
}

class AdCard extends Component<{ ad: AdRecord; placement?: string; onReserve?: (ad: AdRecord) => void }> {
  view() {
    const ad = this.attrs.ad;
    const placement = (this.attrs.placement || ad.slotKey).replace('_', '-');
    const card = (
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

    if (!this.attrs.onReserve || !ad.canReserve) {
      return card;
    }

    return (
      <div className="LowseekaiAdvertising-cardWrap">
        {card}
        {ad.endsAt ? (
          <span className="LowseekaiAdvertising-cardMeta">
            {app.translator.trans('lowseekai-advertising.forum.ends_at', { date: ad.endsAt })}
          </span>
        ) : null}
        <Button
          type="button"
          className="Button Button--secondary LowseekaiAdvertising-reserveButton"
          icon="fas fa-clock"
          onclick={() => this.attrs.onReserve?.(ad)}
        >
          {app.translator.trans('lowseekai-advertising.forum.reserve')}
        </Button>
      </div>
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

    if (data.status === 'approved') return 'fas fa-check-circle';
    if (data.status === 'reserved') return 'fas fa-clock';
    if (['cancelled', 'expired'].includes(data.status || '')) return 'fas fa-undo';

    return 'fas fa-gavel';
  }

  href() {
    return app.route('lowseekai-advertising.index');
  }

  content() {
    const data = this.attrs.notification.content<AdvertisingNotificationData>() || {};
    const key =
      data.status === 'approved'
        ? 'lowseekai-advertising.forum.notification_approved'
        : data.status === 'reserved'
        ? 'lowseekai-advertising.forum.notification_reserved'
        : ['cancelled', 'expired'].includes(data.status || '')
        ? 'lowseekai-advertising.forum.notification_cancelled'
        : data.reviewNote
        ? 'lowseekai-advertising.forum.notification_rejected_with_note'
        : 'lowseekai-advertising.forum.notification_rejected';

    return app.translator.trans(key, {
      title: data.title || '',
      price: data.totalPrice || 0,
      slot: data.slotLabel || '',
      startsAt: data.startsAt || '',
      endsAt: data.endsAt || '',
      estimatedStartAt: data.estimatedStartAt || '',
      waitUntil: data.waitUntil || '',
      note: data.reviewNote || '',
    });
  }

  excerpt() {
    return null;
  }
}

type AutoRenewalModalAttrs = IInternalModalAttrs & {
  titleText: string;
  slotLabel: string;
  durationLabel: string;
  price: number;
  autoRenewalAvailable: boolean;
  isReservation?: boolean;
  onConfirm: () => Promise<void> | void;
  onSubmitOnly: () => Promise<void> | void;
};

class AutoRenewalConfirmationModal extends Modal<AutoRenewalModalAttrs> {
  loadingAction: 'confirm' | 'submitOnly' | null = null;

  className() {
    return 'LowseekaiAdvertisingAutoRenewalModal Modal--small';
  }

  title() {
    if (this.attrs.isReservation) {
      return app.translator.trans('lowseekai-advertising.forum.submit_reservation_confirm_title');
    }

    return app.translator.trans(
      this.attrs.autoRenewalAvailable ? 'lowseekai-advertising.forum.auto_renewal_confirm_title' : 'lowseekai-advertising.forum.submit_confirm_title'
    );
  }

  content() {
    const attrs = this.attrs;
    const currency = app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分';
    const body = attrs.isReservation
      ? app.translator.trans('lowseekai-advertising.forum.submit_reservation_confirm_body', {
          title: attrs.titleText,
          price: attrs.price,
          currency,
        })
      : attrs.autoRenewalAvailable
      ? app.translator.trans('lowseekai-advertising.forum.auto_renewal_confirm_body', {
          title: attrs.titleText,
          price: attrs.price,
          duration: attrs.durationLabel,
          timing: app.translator.trans('lowseekai-advertising.forum.auto_renewal_timing'),
        })
      : app.translator.trans('lowseekai-advertising.forum.submit_confirm_body', {
          title: attrs.titleText,
          price: attrs.price,
          currency,
        });

    return (
      <div className="Modal-body LowseekaiAdvertising-confirmBody">
        <div className="LowseekaiAdvertising-confirmIntro">
          <div className="LowseekaiAdvertising-confirmIntroContent">
            <strong className="LowseekaiAdvertising-confirmIntroTitle">{attrs.titleText}</strong>
            <p>{body}</p>
          </div>
        </div>
        <dl className="LowseekaiAdvertising-confirmSummary">
          <div className="LowseekaiAdvertising-confirmSummaryItem LowseekaiAdvertising-confirmSummaryItem--wide">
            <dt>{app.translator.trans('lowseekai-advertising.forum.slot')}</dt>
            <dd>{attrs.slotLabel}</dd>
          </div>
          <div className="LowseekaiAdvertising-confirmSummaryItem">
            <dt>{app.translator.trans('lowseekai-advertising.forum.duration')}</dt>
            <dd>{attrs.durationLabel}</dd>
          </div>
          <div className="LowseekaiAdvertising-confirmSummaryItem LowseekaiAdvertising-confirmSummaryItem--price">
            <dt>{app.translator.trans('lowseekai-advertising.forum.auto_renewal_price')}</dt>
            <dd>
              {attrs.price} {currency}
            </dd>
          </div>
        </dl>
        <div className="Form-controls LowseekaiAdvertising-confirmActions">
          <div className="LowseekaiAdvertising-confirmActionGroup">
            {attrs.autoRenewalAvailable ? (
              [
                <Button
                  className="Button Button--primary LowseekaiAdvertising-confirmPrimary"
                  icon="fas fa-sync-alt"
                  loading={this.loadingAction === 'confirm'}
                  disabled={this.loadingAction !== null}
                  onclick={() => this.handleAction('confirm')}
                >
                  {app.translator.trans('lowseekai-advertising.forum.auto_renewal_confirm')}
                </Button>,
                <Button
                  className="Button Button--secondary LowseekaiAdvertising-confirmSecondary"
                  icon="fas fa-paper-plane"
                  loading={this.loadingAction === 'submitOnly'}
                  disabled={this.loadingAction !== null}
                  onclick={() => this.handleAction('submitOnly')}
                >
                  {app.translator.trans('lowseekai-advertising.forum.auto_renewal_submit_only')}
                </Button>,
              ]
            ) : (
              <Button
                className="Button Button--primary LowseekaiAdvertising-confirmPrimary"
                icon="fas fa-paper-plane"
                loading={this.loadingAction === 'submitOnly'}
                disabled={this.loadingAction !== null}
                onclick={() => this.handleAction('submitOnly')}
              >
                {app.translator.trans(
                  attrs.isReservation ? 'lowseekai-advertising.forum.submit_reservation_confirm_action' : 'lowseekai-advertising.forum.submit_confirm_action'
                )}
              </Button>
            )}
          </div>
          <Button
            className="Button Button--link LowseekaiAdvertising-confirmCancel"
            icon="fas fa-times"
            disabled={this.loadingAction !== null}
            onclick={() => this.hide()}
          >
            {app.translator.trans('lowseekai-advertising.forum.auto_renewal_cancel')}
          </Button>
        </div>
      </div>
    );
  }

  async handleAction(action: 'confirm' | 'submitOnly') {
    if (this.loadingAction) return;

    this.loadingAction = action;
    m.redraw();

    try {
      if (action === 'confirm') {
        await this.attrs.onConfirm();
      } else {
        await this.attrs.onSubmitOnly();
      }

      this.hide();
    } finally {
      this.loadingAction = null;
      m.redraw();
    }
  }
}

type ConfirmationDetail = {
  label: Mithril.Children;
  value: Mithril.Children;
  emphasis?: boolean;
};

type ConfirmationActionModalAttrs = IInternalModalAttrs & {
  titleText: Mithril.Children;
  bodyText: Mithril.Children;
  details?: ConfirmationDetail[];
  confirmText: Mithril.Children;
  cancelText?: Mithril.Children;
  confirmIcon?: string;
  confirmClassName?: string;
  onConfirm: () => Promise<void> | void;
};

class ConfirmationActionModal extends Modal<ConfirmationActionModalAttrs> {
  loading = false;

  className() {
    return 'LowseekaiAdvertisingConfirmModal Modal--small';
  }

  title() {
    return this.attrs.titleText;
  }

  content() {
    const attrs = this.attrs;

    return (
      <div className="Modal-body LowseekaiAdvertising-confirmBody">
        <div className="LowseekaiAdvertising-confirmIntro">
          <div className="LowseekaiAdvertising-confirmIntroContent">
            <p>{attrs.bodyText}</p>
          </div>
        </div>
        {attrs.details?.length ? (
          <dl className="LowseekaiAdvertising-confirmSummary">
            {attrs.details.map((detail, index) => (
              <div
                className={`LowseekaiAdvertising-confirmSummaryItem${detail.emphasis ? ' LowseekaiAdvertising-confirmSummaryItem--price' : ''}`}
                key={`detail-${index}`}
              >
                <dt>{detail.label}</dt>
                <dd>{detail.value}</dd>
              </div>
            ))}
          </dl>
        ) : null}
        <div className="Form-controls LowseekaiAdvertising-confirmActions">
          <div className="LowseekaiAdvertising-confirmActionGroup">
            <Button
              className={`${attrs.confirmClassName || 'Button Button--primary'} LowseekaiAdvertising-confirmPrimary`}
              icon={attrs.confirmIcon || 'fas fa-check'}
              loading={this.loading}
              disabled={this.loading}
              onclick={() => this.handlePrimaryAction()}
            >
              {attrs.confirmText}
            </Button>
          </div>
          <Button
            className="Button Button--link LowseekaiAdvertising-confirmCancel"
            icon="fas fa-times"
            disabled={this.loading}
            onclick={() => this.hide()}
          >
            {attrs.cancelText || app.translator.trans('lowseekai-advertising.forum.auto_renewal_cancel')}
          </Button>
        </div>
      </div>
    );
  }

  async handlePrimaryAction() {
    if (this.loading) return;

    this.loading = true;
    m.redraw();

    try {
      await this.attrs.onConfirm();
      this.hide();
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}

class AdAutoRenewalNotification extends Notification {
  icon() {
    const data = this.attrs.notification.content<AdvertisingNotificationData>() || {};

    return data.event === 'enabled'
      ? 'fas fa-check-circle'
      : data.event === 'succeeded'
      ? 'fas fa-sync-alt'
      : data.event === 'failed'
      ? 'fas fa-exclamation-circle'
      : 'fas fa-pause-circle';
  }

  href() {
    return app.route('lowseekai-advertising.index');
  }

  content() {
    const data = this.attrs.notification.content<AdvertisingNotificationData>() || {};
    const key =
      data.event === 'enabled'
        ? 'lowseekai-advertising.forum.notification_auto_renewal_enabled'
        : data.event === 'succeeded'
        ? 'lowseekai-advertising.forum.notification_auto_renewal_succeeded'
        : data.event === 'price_changed'
        ? 'lowseekai-advertising.forum.notification_auto_renewal_price_changed'
        : 'lowseekai-advertising.forum.notification_auto_renewal_failed';

    return app.translator.trans(key, {
      title: data.title || '',
      amount: data.amount || 0,
      endsAt: data.endsAt || '',
      reason: data.reason || '',
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
  private autoRenewingAd: number | null = null;
  private cancelingAd: number | null = null;
  private form = {
    slotKey: 'right_sidebar',
    title: '',
    targetUrl: '',
    durationPlan: '1_month',
    autoRenewEnabled: false,
  };

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
  }

  slotByKey(slotKey: string) {
    return this.slotConfigs.find((slot) => slot.key === slotKey);
  }

  isReservationMode(slot: SlotConfig = this.selectedSlot()) {
    const positions = slot.positions?.length
      ? slot.positions
      : Array.from({ length: slot.capacity }, (_, index) => ({
          position: index + 1,
          available: true,
        }));

    return !positions.some((position) => position.available) && Boolean(slot.reservable);
  }

  reservableSlots() {
    return this.slotConfigs.filter((slot) => slot.enabled && Boolean(slot.reservable));
  }

  canReserveAd(ad: AdRecord) {
    if (ad.canReserve) return true;

    const slot = this.slotByKey(ad.slotKey);
    if (!slot?.reservable || !ad.endsAt || ad.autoRenewEnabled) return false;

    const endsAt = new Date(ad.endsAt.replace(' ', 'T'));
    const leadDays = Number(app.forum.attribute('lowseekaiAdvertisingReservationLeadDays') || 15);

    return endsAt.getTime() > Date.now() && endsAt.getTime() <= Date.now() + leadDays * 24 * 60 * 60 * 1000;
  }

  selectReservationSlot(slotKey: string) {
    this.setSlot(slotKey);
    m.redraw();

    window.setTimeout(() => {
      document.querySelector('.LowseekaiAdvertising-apply')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 0);
  }

  reserveAd(ad: AdRecord) {
    this.selectReservationSlot(ad.slotKey);
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
          [
            this.reservationBanner(),
            this.applyForm(),
          ]
        ) : (
          <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_permission')}</p>
        )}
        {app.session.user ? this.myAdsView() : null}
        <section className="LowseekaiAdvertising-list">
          <div className="LowseekaiAdvertising-sectionHeading">
            <h2>{app.translator.trans('lowseekai-advertising.forum.active_ads')}</h2>
          </div>
          {this.ads.length ? (
            <div className="LowseekaiAdvertising-cardGrid LowseekaiAdvertising-cardGrid--active">
              {this.ads.map((ad) => (
                <AdCard ad={{ ...ad, canReserve: this.canReserveAd(ad) }} onReserve={(ad) => this.reserveAd(ad)} key={ad.id} />
              ))}
            </div>
          ) : (
            <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_ads')}</p>
          )}
        </section>
      </div>
    );
  }

  reservationBanner() {
    const slots = this.reservableSlots();
    if (!slots.length) return null;

    return (
      <div className="LowseekaiAdvertising-reservationBanner">
        <i className="fas fa-clock" aria-hidden="true" />
        <div className="LowseekaiAdvertising-reservationBannerBody">
          <strong>{app.translator.trans('lowseekai-advertising.forum.reservation_banner_title')}</strong>
          <span>{app.translator.trans('lowseekai-advertising.forum.reservation_banner_intro')}</span>
          <div className="LowseekaiAdvertising-reservationBannerSlots">
            {slots.map((slot) => (
              <button type="button" onclick={() => this.selectReservationSlot(slot.key)}>
                {slot.label}
              </button>
            ))}
          </div>
        </div>
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
    const selectedSlot = this.selectedSlot();
    const canReserve = this.isReservationMode(selectedSlot);
    const cannotSubmitForSlot = noPositions && !canReserve;

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
        {this.slotAvailabilitySummary(selectedSlot, noPositions, canReserve)}
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
        {app.forum.attribute('lowseekaiAdvertisingAutoRenewalEnabled') && !canReserve ? (
          <div className="Form-group">
            <label>{app.translator.trans('lowseekai-advertising.forum.auto_renewal')}</label>
            <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.auto_renewal_submit_prompt_help')}</p>
          </div>
        ) : null}
        <Button
          className="Button Button--primary"
          icon="fas fa-paper-plane"
          loading={this.submitting}
          disabled={this.submitting || insufficient || cannotSubmitForSlot}
          onclick={() => this.submit()}
        >
          {app.translator.trans(canReserve ? 'lowseekai-advertising.forum.submit_reservation' : 'lowseekai-advertising.forum.submit')}
        </Button>
      </section>
    );
  }

  myAdsView() {
    return (
      <section className="LowseekaiAdvertising-my">
        <h2>{app.translator.trans('lowseekai-advertising.forum.my_ads')}</h2>
        {this.myAds.length ? (
          <div className="LowseekaiAdvertising-cardGrid LowseekaiAdvertising-cardGrid--mine">
            {this.myAds.map((ad) => (
              <div className="LowseekaiAdvertising-myCard" key={ad.id}>
                <img src={ad.imageUrl} alt={ad.title} />
                <div className="LowseekaiAdvertising-myDetails">
                  <strong>{ad.title}</strong>
                  <span>
                    {this.statusLabel(ad.status)}
                    {ad.reviewNote ? ` · ${ad.reviewNote}` : ''}
                  </span>
                  <span>
                    {ad.slotLabel} · {ad.durationLabel} · {ad.totalPrice} {app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分'}
                  </span>
                  {ad.startsAt ? (
                    <span>{app.translator.trans('lowseekai-advertising.forum.starts_at', { date: this.formatDate(ad.startsAt) })}</span>
                  ) : null}
                  {ad.endsAt ? <span>{app.translator.trans('lowseekai-advertising.forum.ends_at', { date: this.formatDate(ad.endsAt) })}</span> : null}
                  {ad.reservationEstimatedStartAt ? (
                    <span>{app.translator.trans('lowseekai-advertising.forum.estimated_release_at', { date: this.formatDate(ad.reservationEstimatedStartAt) })}</span>
                  ) : null}
                  {ad.reservationWaitUntil ? (
                    <span>{app.translator.trans('lowseekai-advertising.forum.reservation_wait_until', { date: this.formatDate(ad.reservationWaitUntil) })}</span>
                  ) : null}
                  {ad.status === 'approved' && ad.autoRenewStatus ? (
                    <span className="LowseekaiAdvertising-autoRenewalStatus">
                      {this.autoRenewalStatusLabel(ad.autoRenewStatus)}
                      {ad.autoRenewPrice ? ` · ${ad.autoRenewPrice} ${app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分'}` : ''}
                      {ad.autoRenewLastAttemptAt
                        ? ` · ${app.translator.trans('lowseekai-advertising.forum.auto_renewal_last_attempt', {
                            date: this.formatDate(ad.autoRenewLastAttemptAt),
                          })}`
                        : ''}
                    </span>
                  ) : null}
                  <div className="LowseekaiAdvertising-myActions">
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
                    {ad.status === 'approved' && (app.forum.attribute('lowseekaiAdvertisingAutoRenewalEnabled') || ad.autoRenewEnabled) ? (
                      <Button
                        type="button"
                        className="Button Button--secondary LowseekaiAdvertising-autoRenewButton"
                        icon={ad.autoRenewEnabled ? 'fas fa-toggle-on' : 'fas fa-toggle-off'}
                        loading={this.autoRenewingAd === ad.id}
                        disabled={this.autoRenewingAd !== null}
                        onclick={() => this.toggleAutoRenewal(ad)}
                      >
                        {ad.autoRenewEnabled
                          ? app.translator.trans('lowseekai-advertising.forum.auto_renewal_disable_button')
                          : app.translator.trans('lowseekai-advertising.forum.auto_renewal_enable_button')}
                      </Button>
                    ) : null}
                    {ad.isReservation && ['pending', 'reserved'].includes(ad.status) ? (
                      <Button
                        type="button"
                        className="Button Button--secondary LowseekaiAdvertising-cancelReservationButton"
                        icon="fas fa-times"
                        loading={this.cancelingAd === ad.id}
                        disabled={this.cancelingAd !== null}
                        onclick={() => this.cancelReservation(ad)}
                      >
                        {app.translator.trans('lowseekai-advertising.forum.cancel_reservation')}
                      </Button>
                    ) : null}
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.no_my_ads')}</p>
        )}
      </section>
    );
  }

  slotAvailabilitySummary(slot: SlotConfig, noPositions: boolean, canReserve: boolean) {
    if (!noPositions) {
      return <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.slot_available_now')}</p>;
    }

    if (canReserve) {
      return (
        <div className="helpText LowseekaiAdvertising-slotStatus">
          <span>{app.translator.trans('lowseekai-advertising.forum.slot_full_reservable')}</span>
          {slot.earliestReleaseAt ? (
            <span>{app.translator.trans('lowseekai-advertising.forum.estimated_release_at', { date: this.formatDate(slot.earliestReleaseAt) })}</span>
          ) : null}
          <span>{app.translator.trans('lowseekai-advertising.forum.reservation_queue_count', { count: slot.reservationQueueCount || 0 })}</span>
          {slot.myReservationQueuePosition ? (
            <span>{app.translator.trans('lowseekai-advertising.forum.my_reservation_queue_position', { position: slot.myReservationQueuePosition })}</span>
          ) : null}
        </div>
      );
    }

    return <p className="helpText">{app.translator.trans('lowseekai-advertising.forum.slot_full_unavailable')}</p>;
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

  renew(ad: AdRecord) {
    const currency = app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分';
    const renewalPrice = ad.renewalPrice || ad.totalPrice;

    app.modal.show(ConfirmationActionModal, {
      titleText: app.translator.trans('lowseekai-advertising.forum.renew_confirm_title'),
      bodyText: app.translator.trans('lowseekai-advertising.forum.renew_confirm_body', {
        title: ad.title,
        price: renewalPrice,
        currency,
      }),
      confirmText: app.translator.trans('lowseekai-advertising.forum.renew_confirm_action'),
      confirmIcon: 'fas fa-redo',
      details: [
        { label: app.translator.trans('lowseekai-advertising.forum.slot'), value: ad.slotLabel },
        { label: app.translator.trans('lowseekai-advertising.forum.duration'), value: ad.durationLabel },
        {
          label: app.translator.trans('lowseekai-advertising.forum.auto_renewal_price'),
          value: `${renewalPrice} ${currency}`,
          emphasis: true,
        },
        {
          label: app.translator.trans('lowseekai-advertising.forum.current_expires_at'),
          value: ad.endsAt ? this.formatDate(ad.endsAt) : '-',
        },
      ],
      onConfirm: () => this.confirmRenew(ad),
    });
  }

  async confirmRenew(ad: AdRecord) {
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

    const isReservation = this.isReservationMode();

    app.modal.show(AutoRenewalConfirmationModal, {
      titleText: this.form.title,
      slotLabel: this.selectedSlot().label,
      durationLabel: this.selectedPlan().label,
      price: total,
      autoRenewalAvailable: !isReservation && Boolean(app.forum.attribute('lowseekaiAdvertisingAutoRenewalEnabled')),
      isReservation,
      onConfirm: () => this.submitApplication(true),
      onSubmitOnly: () => this.submitApplication(false),
    });
  }

  async submitApplication(autoRenewEnabled: boolean) {
    this.submitting = true;
    try {
      await app.request({
        method: 'POST',
        url: this.apiUrl('/advertising/ads'),
        body: {
          data: {
            attributes: {
              ...this.form,
              autoRenewEnabled,
              imagePath: this.uploadedPath,
            },
          },
        },
      });
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.forum.pending_success'));
      this.form = {
        slotKey: this.form.slotKey,
        title: '',
        targetUrl: '',
        durationPlan: '1_month',
        autoRenewEnabled: false,
      };
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

  toggleAutoRenewal(ad: AdRecord) {
    const enabled = !ad.autoRenewEnabled;
    const currency = app.forum.attribute('lowseekaiAdvertisingCurrencyName') || '积分';
    const renewalPrice = ad.autoRenewPrice || ad.renewalPrice || ad.totalPrice;
    const details: ConfirmationDetail[] = [
      { label: app.translator.trans('lowseekai-advertising.forum.slot'), value: ad.slotLabel },
      {
        label: app.translator.trans('lowseekai-advertising.forum.auto_renewal_price'),
        value: `${renewalPrice} ${currency}`,
        emphasis: true,
      },
    ];

    if (enabled && ad.nextAutoRenewAt) {
      details.push({
        label: app.translator.trans('lowseekai-advertising.forum.next_auto_renew_at'),
        value: this.formatDate(ad.nextAutoRenewAt),
      });
    }

    app.modal.show(ConfirmationActionModal, {
      titleText: app.translator.trans(
        enabled ? 'lowseekai-advertising.forum.auto_renewal_enable_title' : 'lowseekai-advertising.forum.auto_renewal_disable_title'
      ),
      bodyText: app.translator.trans(
        enabled ? 'lowseekai-advertising.forum.auto_renewal_enable_body' : 'lowseekai-advertising.forum.auto_renewal_disable_body',
        { title: ad.title, price: renewalPrice, currency }
      ),
      confirmText: app.translator.trans(
        enabled ? 'lowseekai-advertising.forum.auto_renewal_enable_confirm_action' : 'lowseekai-advertising.forum.auto_renewal_disable_confirm_action'
      ),
      confirmIcon: enabled ? 'fas fa-toggle-on' : 'fas fa-toggle-off',
      details,
      onConfirm: () => this.confirmToggleAutoRenewal(ad, enabled),
    });
  }

  async confirmToggleAutoRenewal(ad: AdRecord, enabled: boolean) {
    this.autoRenewingAd = ad.id;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: this.apiUrl(`/advertising/ads/${ad.id}/auto-renew`),
        body: { data: { attributes: { enabled } } },
      });
      app.alerts.show(
        { type: 'success' },
        app.translator.trans(enabled ? 'lowseekai-advertising.forum.auto_renewal_enabled' : 'lowseekai-advertising.forum.auto_renewal_disabled')
      );
      await this.load();
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.autoRenewingAd = null;
      m.redraw();
    }
  }

  cancelReservation(ad: AdRecord) {
    app.modal.show(ConfirmationActionModal, {
      titleText: app.translator.trans('lowseekai-advertising.forum.cancel_reservation_title'),
      bodyText: app.translator.trans('lowseekai-advertising.forum.cancel_reservation_body', { title: ad.title }),
      confirmText: app.translator.trans('lowseekai-advertising.forum.cancel_reservation_confirm'),
      confirmIcon: 'fas fa-times',
      confirmClassName: 'Button Button--danger',
      details: [
        { label: app.translator.trans('lowseekai-advertising.forum.slot'), value: ad.slotLabel },
        { label: app.translator.trans('lowseekai-advertising.forum.duration'), value: ad.durationLabel },
      ],
      onConfirm: () => this.confirmCancelReservation(ad),
    });
  }

  async confirmCancelReservation(ad: AdRecord) {
    this.cancelingAd = ad.id;
    m.redraw();

    try {
      await app.request({ method: 'POST', url: this.apiUrl(`/advertising/ads/${ad.id}/cancel`) });
      app.alerts.show({ type: 'success' }, app.translator.trans('lowseekai-advertising.forum.cancel_reservation_success'));
      await this.load();
    } catch (error) {
      app.alerts.show({ type: 'error' }, this.errorMessage(error));
    } finally {
      this.cancelingAd = null;
      m.redraw();
    }
  }

  autoRenewalStatusLabel(status: string) {
    const key = ['active', 'disabled', 'failed', 'price_changed'].includes(status) ? status : 'disabled';

    return app.translator.trans(`lowseekai-advertising.forum.auto_renewal_status_${key}`);
  }

  statusLabel(status: string) {
    const key = ['pending', 'reserved', 'approved', 'rejected', 'expired', 'hidden', 'cancelled'].includes(status) ? status : 'pending';

    return app.translator.trans(`lowseekai-advertising.forum.status_${key}`);
  }

  formatDate(value: string) {
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value)) return value;

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
    if (!slot || isMobileViewport()) {
      this.loaded = true;
      return;
    }

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
    if (!slot || isMobileViewport() || !app.forum.attribute('lowseekaiAdvertisingEnabled') || !this.config?.enabled) {
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

    const visibleEmptySlotLimit = slot === 'top' ? capacity : Math.max(1, Math.min(capacity, Number(this.config.displayCount) || 3));
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
  app.notificationComponents.lowseekaiAdvertisingAutoRenewal = AdAutoRenewalNotification;

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
    items.add('lowseekaiAdvertisingAutoRenewal', {
      name: 'lowseekaiAdvertisingAutoRenewal',
      icon: 'fas fa-sync-alt',
      label: app.translator.trans('lowseekai-advertising.forum.notification_auto_renewal_label'),
    });
  });

  extend(IndexSidebar.prototype, 'items', (items: any) => {
    if (app.current?.get('routeName') !== 'index' || isMobileViewport()) return;

    items.add('lowseekai-advertising-left-sidebar', <AdvertisingSlotGrid slot="left_sidebar" />, -1000);
  });

  extend(IndexPage.prototype, 'contentItems', (items: any) => {
    if (app.current?.get('routeName') !== 'index' || isMobileViewport()) return;
    items.add('lowseekai-advertising-right-rail', <AdvertisingSlotGrid slot="right_sidebar" />, 80);
  });

  extend(PageStructure.prototype, 'mainItems', (items: any) => {
    if (app.current?.get('routeName') !== 'index' || isMobileViewport()) return;

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
