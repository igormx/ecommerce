import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { listCategories } from '../api/categories.js';
import { createProduct, getProduct, updateProduct } from '../api/products.js';
import ErrorBanner from '../components/ErrorBanner.jsx';
import FieldError, { fieldErrorId } from '../components/FieldError.jsx';
import Spinner from '../components/Spinner.jsx';
import { parsePriceToCents } from '../lib/money.js';

// Mirrors the server limits (backend ProductService); the server stays authoritative.
const MAX_SKU = 32;
const MAX_NAME = 255;
const MAX_CATEGORY = 100;
const MAX_DESCRIPTION_BYTES = 65535;
const MAX_PRICE_CENTS = 9_999_999_999;
const MAX_STOCK = 4_294_967_295;
const MAX_WEIGHT_KG = 99999.999;

const EMPTY = { sku: '', name: '', description: '', category: '', price: '', stock: '', weightKg: '' };

// Form input name -> API body key, for mapping server `fields` onto inputs.
const SERVER_KEY = { price: 'priceCents' };
const serverKey = (name) => SERVER_KEY[name] ?? name;

function toForm(product) {
  return {
    sku: product.sku ?? '',
    name: product.name ?? '',
    description: product.description ?? '',
    category: product.category ?? '',
    price: (product.priceCents / 100).toFixed(2),
    stock: String(product.stock ?? ''),
    weightKg: product.weightKg == null ? '' : String(product.weightKg),
  };
}

/** @returns {{ body?: object, errors: Record<string, string> }} keyed by input name */
export function validate(form) {
  const errors = {};
  const sku = form.sku.trim();
  const name = form.name.trim();
  const description = form.description.trim();
  const category = form.category.trim();
  const stockText = form.stock.trim();
  const weightText = form.weightKg.trim();

  if (sku.length < 1 || sku.length > MAX_SKU) errors.sku = `SKU must be 1 to ${MAX_SKU} characters`;
  if (name.length < 1 || name.length > MAX_NAME) errors.name = `Name must be 1 to ${MAX_NAME} characters`;
  if (category.length > MAX_CATEGORY) errors.category = `Category must be at most ${MAX_CATEGORY} characters`;
  if (new TextEncoder().encode(description).length > MAX_DESCRIPTION_BYTES) {
    errors.description = 'Description is too long';
  }

  const priceCents = parsePriceToCents(form.price);
  if (priceCents === null) errors.price = 'Enter a price like 19.99 (no more than two decimals)';
  else if (priceCents > MAX_PRICE_CENTS) errors.price = 'Price is too large';

  let stock;
  if (!/^\d+$/.test(stockText) || Number(stockText) > MAX_STOCK) {
    errors.stock = 'Stock must be a whole number, 0 or more';
  } else {
    stock = Number(stockText);
  }

  let weightKg = null;
  if (weightText !== '') {
    if (!/^\d+(\.\d{1,3})?$/.test(weightText) || Number(weightText) > MAX_WEIGHT_KG) {
      errors.weightKg = `Weight must be 0 to ${MAX_WEIGHT_KG} with up to three decimals`;
    } else {
      weightKg = Number(weightText);
    }
  }

  if (Object.keys(errors).length > 0) return { errors };
  return {
    errors,
    body: {
      sku,
      name,
      description: description === '' ? null : description,
      category: category === '' ? null : category,
      priceCents,
      stock,
      weightKg,
    },
  };
}

export default function ProductFormPage({ mode }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const isEdit = mode === 'edit';

  const [form, setForm] = useState(EMPTY);
  const [loading, setLoading] = useState(isEdit);
  const [notFound, setNotFound] = useState(false);
  const [loadError, setLoadError] = useState(null);
  const [categories, setCategories] = useState([]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    listCategories().then(
      (list) => {
        if (!cancelled) setCategories(Array.isArray(list) ? list : []);
      },
      () => {
        // Suggestions are optional.
      }
    );
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!isEdit) return undefined;
    let cancelled = false;
    setLoading(true);
    setNotFound(false);
    setLoadError(null);
    getProduct(id).then(
      (product) => {
        if (cancelled) return;
        setForm(toForm(product));
        setLoading(false);
      },
      (err) => {
        if (cancelled) return;
        if (err?.status === 404 || err?.type === 'RESOURCE_NOT_FOUND') setNotFound(true);
        else setLoadError(err);
        setLoading(false);
      }
    );
    return () => {
      cancelled = true;
    };
  }, [isEdit, id]);

  function setValue(name, value) {
    setForm((f) => ({ ...f, [name]: value }));
  }

  async function handleSubmit(e) {
    e.preventDefault();
    if (submitting) return;

    setFormError(null);
    const { body, errors } = validate(form);
    setFieldErrors(errors);
    if (!body) return;

    setSubmitting(true);
    try {
      if (isEdit) await updateProduct(id, body);
      else await createProduct(body);
      navigate('/products');
    } catch (err) {
      setSubmitting(false);
      if (err?.type === 'VALIDATION_ERROR' && err.fields) {
        const mapped = {};
        let matched = false;
        for (const name of Object.keys(EMPTY)) {
          const message = err.fields[serverKey(name)];
          if (message) {
            mapped[name] = message;
            matched = true;
          }
        }
        setFieldErrors(mapped);
        if (!matched) setFormError(err);
      } else if (err?.type === 'CONFLICT') {
        setFieldErrors({ sku: 'SKU already exists' });
      } else if (err?.status === 404 || err?.type === 'RESOURCE_NOT_FOUND') {
        setNotFound(true);
      } else {
        setFormError(err);
      }
    }
  }

  const title = isEdit ? 'Edit product' : 'New product';

  if (loading) {
    return (
      <section className="page">
        <h1>{title}</h1>
        <Spinner />
      </section>
    );
  }

  if (notFound) {
    return (
      <section className="page">
        <h1>{title}</h1>
        <p role="alert">Product not found. It may have been deleted.</p>
        <Link to="/products">Back to products</Link>
      </section>
    );
  }

  if (loadError) {
    return (
      <section className="page">
        <h1>{title}</h1>
        <ErrorBanner error={loadError} />
        <Link to="/products">Back to products</Link>
      </section>
    );
  }

  function field(name, label, props = {}) {
    const Tag = props.as ?? 'input';
    const { as: _as, ...rest } = props;
    return (
      <div className="form-field">
        <label htmlFor={name}>{label}</label>
        <Tag
          id={name}
          name={name}
          value={form[name]}
          onChange={(e) => setValue(name, e.target.value)}
          aria-invalid={fieldErrors[name] ? 'true' : undefined}
          aria-describedby={fieldErrors[name] ? fieldErrorId(name) : undefined}
          {...rest}
        />
        <FieldError fields={fieldErrors} name={name} />
      </div>
    );
  }

  return (
    <section className="page">
      <h1>{title}</h1>
      {formError && <ErrorBanner error={formError} />}
      <form className="product-form" onSubmit={handleSubmit} noValidate>
        {field('sku', 'SKU', { type: 'text', required: true, autoComplete: 'off' })}
        {field('name', 'Name', { type: 'text', required: true })}
        {field('description', 'Description', { as: 'textarea', rows: 4 })}
        {field('category', 'Category', { type: 'text', list: 'category-options' })}
        <datalist id="category-options">
          {categories.map((c) => (
            <option key={c} value={c} />
          ))}
        </datalist>
        {field('price', 'Price (USD)', { type: 'text', inputMode: 'decimal', required: true })}
        {field('stock', 'Stock', { type: 'text', inputMode: 'numeric', required: true })}
        {field('weightKg', 'Weight (kg, optional)', { type: 'text', inputMode: 'decimal' })}
        <div className="form-actions">
          <button type="submit" className="button" disabled={submitting}>
            {submitting ? 'Saving…' : isEdit ? 'Save changes' : 'Create product'}
          </button>
          <Link to="/products">Cancel</Link>
        </div>
      </form>
    </section>
  );
}
