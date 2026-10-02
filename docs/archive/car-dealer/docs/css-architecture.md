# معمارية CSS — ثيم Car Dealer
# CSS Architecture — Car Dealer Theme

## نظرة عامة

تم إعادة هيكلة CSS في ثيم Car Dealer ليتبع نظاماً منظماً وقابلاً للصيانة بناءً على مبادئ **BEM** (Block, Element, Modifier) و **ITCSS** (Inverted Triangle CSS).

---

## هيكل المجلدات

```
assets/css/
├── abstracts/              # المتغيرات والوظائف العامة
│   └── _variables.css      # جميع المتغيرات المركزية
├── base/                   # الأنماط الأساسية
│   ├── _reset.css          # إعادة تعيين المتصفح
│   └── _typography.css     # الطباعة والعناوين
├── components/             # مكونات واجهة المستخدم
│   ├── _buttons.css        # الأزرار
│   ├── _cards.css          # البطاقات
│   ├── _forms.css          # النماذج
│   └── _floating.css       # الأزرار العائمة
├── layout/                 # تخطيط الصفحات
│   ├── _header.css         # الترويسة
│   ├── _footer.css         # التذييل
│   └── _grid.css           # الشبكة والحاوية
├── pages/                  # أنماط الصفحات المحددة
│   ├── _home.css           # الصفحة الرئيسية
│   ├── _cars.css           # صفحات السيارات
│   ├── _account.css        # صفحة الحساب
│   └── _admin.css          # لوحة التحكم
├── themes/                 # السمات
│   └── _auto-brands.css    # سمة AUTO BRANDS
├── main.css                # الملف الرئيسي (يستورد جميع الملفات)
└── home-v2.css             # أنماط Home V2 الفريدة
```

---

## نظام التسمية (Naming Convention)

### البادئات المستخدمة:

| البادئة | الاستخدام | مثال |
|---------|-----------|------|
| `cd-` | مكونات Car Dealer الأساسية | `.cd-ajax-form`, `.cd-tool` |
| `ab-` | مكونات AUTO BRANDS | `.ab-hero`, `.ab-car-hero` |
| `cd-admin-` | مكونات لوحة التحكم | `.cd-admin-sidebar`, `.cd-admin-card` |

### بنية BEM:

```css
/* Block */
.car-card { }

/* Element */
.car-card__title { }
.car-card__price { }

/* Modifier */
.car-card--featured { }
.car-card--compact { }
```

---

## نظام المتغيرات

### الألوان الأساسية:
```css
--cd-color-primary: #1A365D;      /* أزرق داكن */
--cd-color-accent: #D02B2B;       /* أحمر تفاعلي */
--cd-color-secondary: #2B4C7E;    /* كحلي باهت */
--cd-color-bg: #F3F4F6;           /* خلفية رمادية */
--cd-color-surface: #FFFFFF;       /* سطح أبيض */
--cd-color-muted: #627D98;        /* نص ثانوي */
--cd-color-line: #D9E2EC;          /* حدود */
```

### ألوان AUTO BRANDS:
```css
--ab-color-blue: #0669D9;
--ab-color-red: #E21D2F;
--ab-color-black: #05070B;
```

### المسافات:
```css
--cd-spacing-xs: 0.25rem;   /* 4px */
--cd-spacing-sm: 0.5rem;    /* 8px */
--cd-spacing-md: 1rem;      /* 16px */
--cd-spacing-lg: 1.5rem;    /* 24px */
--cd-spacing-xl: 2rem;      /* 32px */
--cd-spacing-2xl: 3rem;     /* 48px */
--cd-spacing-3xl: 4rem;     /* 64px */
--cd-spacing-4xl: 5rem;     /* 80px */
```

### الانحناءات:
```css
--cd-radius-sm: 6px;
--cd-radius-md: 8px;
--cd-radius-lg: 12px;
--cd-radius-xl: 16px;
--cd-radius-full: 999px;
```

### الظلال:
```css
--cd-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.12);
--cd-shadow-md: 0 4px 15px rgba(0, 0, 0, 0.08);
--cd-shadow-lg: 0 18px 50px rgba(15, 23, 42, 0.09);
--cd-shadow-xl: 0 28px 90px rgba(0, 0, 0, 0.45);
```

---

## ترتيب تحميل الملفات

```
1. abstracts/_variables.css   ← المتغيرات أولاً
2. base/_reset.css            ← إعادة التعيين
3. base/_typography.css       ← الطباعة
4. components/*.css           ← المكونات
5. layout/*.css               ← التخطيط
6. pages/*.css                ← الصفحات
7. themes/*.css               ← السمات
```

---

## قواعد الكتابة

### 1. استخدم المتغيرات دائماً:
```css
/* ❌ لا تفعل هذا */
color: #1A365D;

/* ✅ افعل هذا */
color: var(--cd-color-primary);
```

### 2. اترك مسافات بين الأقسام:
```css
/* ── Section Name ── */
.component {
  /* styles */
}
```

### 3. رتب الخصائص بشكل متسق:
```css
.component {
  /* التخطيط */
  display: flex;
  position: relative;
  
  /* الأبعاد */
  width: 100%;
  height: auto;
  
  /* المسافات */
  padding: var(--cd-spacing-md);
  margin: 0;
  
  /* الألوان */
  background: var(--cd-color-surface);
  color: var(--cd-color-text);
  
  /* الخطوط */
  font-size: var(--cd-font-size-base);
  font-weight: 700;
  
  /* التأثيرات */
  border-radius: var(--cd-radius-md);
  box-shadow: var(--cd-shadow-md);
}
```

### 4. استخدم RTL-aware properties:
```css
/* ❌ لا تفعل هذا */
margin-left: 1rem;
padding-right: 0.5rem;

/* ✅ افعل هذا */
margin-inline-start: 1rem;
padding-inline-end: 0.5rem;
```

---

## نقاط التوقف (Breakpoints)

```css
/* الأجهزة الصغيرة جداً */
@media (max-width: 600px) { }

/* الأجهزة اللوحية */
@media (max-width: 900px) { }

/* الشاشات الكبيرة */
@media (min-width: 1200px) { }
```

---

## المهام القادمة

### المرحلة 3: التنفيذ
- [ ] تحديث جميع ملفات admin لاستخدام المتغيرات الجديدة
- [ ] تحديث ملفات vehicle-list و account و login
- [ ] إنشاء ملف build لدمج وتصغير CSS
- [ ] اختبار جميع الصفحات

### المرحلة 4: الاختبار
- [ ] اختبار التوافقية مع المتصفحات
- [ ] اختبار الأداء
- [ ] اختبار إمكانية الوصول

### المرحلة 5: النشر
- [ ] النقل التدريجي
- [ ] المراقبة
- [ ] التوثيق النهائي
